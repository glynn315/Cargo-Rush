import { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { WebView, WebViewMessageEvent } from 'react-native-webview';

import { Brand, Radius } from '@/constants/theme';

import { leafletPage } from './leaflet-page';
import { MapCanvasProps, parseMapMessage, routeKey } from './map-canvas.types';

/**
 * The map on a handset: the Leaflet page in a `WebView`.
 *
 * The page is built once, from what the screen opened on, and never rebuilt —
 * a new `source` reloads the WebView, and re-loading the map on every keystroke
 * in the search box above it would be a blank grey box for most of the time
 * somebody is typing. Every later change is a one-line call injected into the
 * page that is already running.
 */
export function MapCanvas({
  point,
  onPick,
  markers,
  selectedId,
  onSelect,
  interactive = true,
  route = null,
  routeLabels,
  height = 260,
}: MapCanvasProps) {
  const webview = useRef<WebView>(null);
  const [html] = useState(() =>
    leafletPage(point, { markers, selectedId, interactive, route, routeLabels }),
  );

  /** Injection is silently lost before the page loads, so it is held here. */
  const ready = useRef(false);
  const pending = useRef<string[]>([]);

  /** The pin this component was told about is already drawn on the page. */
  const fromPage = useRef(false);
  const mounted = useRef(false);

  /**
   * Which route the page is already drawing.
   *
   * Keyed on the shape rather than the object: the route is fetched after the
   * screen mounts and then does not change for the run, and a re-render with an
   * equal-but-new array must not re-fit the map out from under a driver who has
   * panned it.
   */
  const drawnRoute = useRef(routeKey(route));

  /** Were the carriers baked into the page when it was built? */
  const baked = useRef((markers ?? []).length > 0);
  /** Has the map already fitted itself around a set of carriers? */
  const fitted = useRef(baked.current);
  const firstDraw = useRef(true);

  const run = (script: string) => {
    if (ready.current) {
      webview.current?.injectJavaScript(`${script} true;`);

      return;
    }

    pending.current.push(script);
  };

  // The coordinates, not the point object: a pin whose *name* has just arrived
  // from the geocoder is the same pin, and redrawing it would re-centre the
  // map a second after somebody tapped it.
  const lat = point?.lat ?? null;
  const lng = point?.lng ?? null;

  const key = routeKey(route);

  useEffect(() => {
    if (key === drawnRoute.current) return;

    drawnRoute.current = key;

    run(
      `window.__setRoute && window.__setRoute(${JSON.stringify({
        points: route?.points ?? [],
        labels: routeLabels ?? null,
        dashed: route?.source === 'straight',
        fit: true,
      })});`,
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  useEffect(() => {
    // The point the map opened on is baked into the page already.
    if (!mounted.current) {
      mounted.current = true;

      return;
    }

    // A pin the user just placed is on screen where their thumb left it.
    // Re-centring on it would move the map out from under them.
    if (fromPage.current) {
      fromPage.current = false;

      return;
    }

    if (lat === null || lng === null) {
      run('window.__clearPin && window.__clearPin();');

      return;
    }

    run(`window.__setPin && window.__setPin(${lat}, ${lng}, true);`);
  }, [lat, lng]);

  /**
   * The carriers, and which one is chosen.
   *
   * Serialised whole on every change, because the page redraws them whole. The
   * map fits itself around them the *first* time a set arrives and not after:
   * re-fitting when somebody taps a card would drag the map away from the pin
   * they were looking at.
   */
  const signature = JSON.stringify(markers ?? []);

  useEffect(() => {
    const list = markers ?? [];

    if (firstDraw.current) {
      firstDraw.current = false;

      // Already in the page's own script, so there is nothing to push.
      if (baked.current) return;
    }

    const fit = !fitted.current && list.length > 0;
    if (fit) fitted.current = true;

    run(
      `window.__setMarkers && window.__setMarkers(${JSON.stringify({
        markers: list,
        selected: selectedId ?? null,
        fit,
      })});`,
    );
    // `signature` stands in for the array: a new array of the same carriers is
    // the same map, and re-rendering the parent on every keystroke would
    // otherwise redraw it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature, selectedId]);

  const onMessage = (event: WebViewMessageEvent) => {
    const message = parseMapMessage(event.nativeEvent.data);
    if (message === null) return;

    if (message.type === 'ready') {
      ready.current = true;

      pending.current.forEach((script) =>
        webview.current?.injectJavaScript(`${script} true;`),
      );
      pending.current = [];

      return;
    }

    if (message.type === 'select') {
      onSelect?.(message.id);

      return;
    }

    fromPage.current = true;
    onPick?.(message.lat, message.lng);
  };

  return (
    <View style={[styles.frame, { height }]}>
      <WebView
        ref={webview}
        source={{ html }}
        onMessage={onMessage}
        // The page is ours and inline, so there is no origin to allow; this is
        // what lets a `html` source load at all.
        originWhitelist={['*']}
        javaScriptEnabled
        // The map does its own panning. Letting the WebView scroll as well
        // means a drag sometimes moves the page instead of the map.
        scrollEnabled={false}
        // Android draws a white page over the tint for an instant otherwise.
        androidLayerType="hardware"
        style={styles.web}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  frame: {
    overflow: 'hidden',
    borderRadius: Radius.card,
    borderWidth: 1,
    borderColor: Brand.line,
    backgroundColor: Brand.tint,
  },
  web: { flex: 1, backgroundColor: Brand.tint },
});
