import { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';

import { Brand, Radius } from '@/constants/theme';

import { leafletPage } from './leaflet-page';
import { MapCanvasProps, parseMapMessage, routeKey } from './map-canvas.types';

/**
 * The map on the web build: the same Leaflet page, in an `iframe`.
 *
 * `react-native-webview` has no web platform, and `expo start --web` is how
 * this app is demonstrated on a laptop — so rather than let the map be a blank
 * box there, the one thing that differs is swapped out. Metro resolves this
 * file on web and the native one everywhere else; the page, the tiles, the
 * geocoder and the contract are identical.
 *
 * The talking is by `postMessage` in both directions, since there is no
 * script injection into a frame the host does not own the origin of.
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
  const frame = useRef<HTMLIFrameElement | null>(null);
  const [html] = useState(() =>
    leafletPage(point, { markers, selectedId, interactive, route, routeLabels }),
  );

  const ready = useRef(false);
  const pending = useRef<object[]>([]);
  const fromPage = useRef(false);
  const mounted = useRef(false);

  /** Which route the page is already drawing — see the native canvas. */
  const drawnRoute = useRef(routeKey(route));

  /** Were the carriers baked into the page when it was built? */
  const baked = useRef((markers ?? []).length > 0);
  /** Has the map already fitted itself around a set of carriers? */
  const fitted = useRef(baked.current);
  const firstDraw = useRef(true);

  const post = (message: object) => {
    if (!ready.current) {
      pending.current.push(message);

      return;
    }

    frame.current?.contentWindow?.postMessage(JSON.stringify(message), '*');
  };

  useEffect(() => {
    const onMessage = (event: MessageEvent) => {
      // Every frame on the page can post here, including browser extensions.
      if (event.source !== frame.current?.contentWindow) return;

      const message = parseMapMessage(event.data);
      if (message === null) return;

      if (message.type === 'ready') {
        ready.current = true;

        pending.current.forEach((queued) =>
          frame.current?.contentWindow?.postMessage(JSON.stringify(queued), '*'),
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

    window.addEventListener('message', onMessage);

    return () => window.removeEventListener('message', onMessage);
  }, [onPick, onSelect]);

  // The coordinates, not the point object: a pin whose *name* has just arrived
  // from the geocoder is the same pin, and redrawing it would re-centre the
  // map a second after somebody clicked it.
  const lat = point?.lat ?? null;
  const lng = point?.lng ?? null;

  useEffect(() => {
    if (!mounted.current) {
      mounted.current = true;

      return;
    }

    if (fromPage.current) {
      fromPage.current = false;

      return;
    }

    post(
      lat === null || lng === null
        ? { type: 'clear' }
        : { type: 'set', lat, lng, recentre: true },
    );
  }, [lat, lng]);

  /**
   * The route, once it arrives.
   *
   * Keyed on its shape rather than the object: it is fetched after the screen
   * mounts and then does not change for the run, and a re-render with an
   * equal-but-new array must not re-fit the map out from under somebody who has
   * panned it.
   */
  const key = routeKey(route);

  useEffect(() => {
    if (key === drawnRoute.current) return;

    drawnRoute.current = key;

    post({
      type: 'route',
      points: route?.points ?? [],
      labels: routeLabels ?? null,
      dashed: route?.source === 'straight',
      fit: true,
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  /**
   * The carriers, and which one is chosen.
   *
   * Sent whole on every change, because the page redraws them whole. The map
   * fits itself around them the *first* time a set arrives and not after:
   * re-fitting when somebody clicks a card would drag the map away from the pin
   * they were looking at.
   */
  const signature = JSON.stringify(markers ?? []);

  useEffect(() => {
    const list = markers ?? [];

    if (firstDraw.current) {
      firstDraw.current = false;

      // Already in the page's own script, so there is nothing to send.
      if (baked.current) return;
    }

    const fit = !fitted.current && list.length > 0;
    if (fit) fitted.current = true;

    post({ type: 'markers', markers: list, selected: selectedId ?? null, fit });
    // `signature` stands in for the array: a new array of the same carriers is
    // the same map, and re-rendering the parent on every keystroke would
    // otherwise redraw it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature, selectedId]);

  return (
    <View style={[styles.frame, { height }]}>
      <iframe
        ref={frame}
        srcDoc={html}
        title={
          interactive
            ? 'Map. Click to place the pin, or search above.'
            : 'Map of the carriers near you. Click a pin to choose one.'
        }
        style={{ border: 0, width: '100%', height: '100%', display: 'block' }}
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
});
