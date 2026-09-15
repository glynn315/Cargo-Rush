import { GeoPoint, RouteLine } from '@/models/geo/geo.model';

import { MapMarker } from './map-canvas.types';

/**
 * Where the map opens when there is nothing to centre on — Iponan, Cagayan de
 * Oro, which is where the fleet actually runs from. The same default the back
 * office uses, so both clients open on the same yard.
 */
const DEFAULT_CENTRE: [number, number] = [8.4856, 124.5808];

/** Street level. A barangay default earns it; a metro-wide guess would not. */
const ZOOM = 13;

/**
 * The map itself: Leaflet and OpenStreetMap tiles, in a page.
 *
 * The back office draws its picker with Leaflet directly. React Native has no
 * Leaflet, and the two native map libraries would each cost something real —
 * `expo-maps` is alpha, needs a development build and does not run on web at
 * all, and Google Maps wants a billing account this install does not have. A
 * page in a `WebView` is the same map, the same tiles and the same behaviour
 * as the desk, on a dependency that ships inside Expo Go.
 *
 * It reports and receives, and holds no state worth keeping: the coordinates
 * live in React, and this is a view of them that happens to be interactive.
 *
 *   page -> host   `{ type: 'pick', lat, lng }` on a tap or a dragged pin
 *   page -> host   `{ type: 'select', id }` when a carrier pin is tapped
 *   host -> page   `window.__setPin(lat, lng)` / `window.__clearPin()`
 *   host -> page   `window.__setMarkers(json)` — the carriers, and which is chosen
 *   host -> page   `window.__setRoute(json)` — the road from A to B
 *
 * Three kinds of thing are drawn, and they are deliberately not the same shape.
 * **The pin** is the person's own: a standard teardrop marker, draggable when
 * the map is interactive, because a tap on a phone is a thumb wide and the yard
 * gate is not. **The carriers** are circles with their name beside them —
 * chosen from, never moved, and drawn as circles precisely so nobody tries to
 * drag one. And **the route** is a line with a dot at each end: read, not
 * touched, and the map fits itself around the whole of it.
 */
export function leafletPage(
  initial: GeoPoint | null,
  options: {
    markers?: MapMarker[];
    selectedId?: string | null;
    interactive?: boolean;
    route?: RouteLine | null;
    routeLabels?: { from: string; to: string };
  } = {},
): string {
  const centre = initial ? [initial.lat, initial.lng] : DEFAULT_CENTRE;
  const interactive = options.interactive !== false;
  const markers = options.markers ?? [];
  const route = options.route ?? null;

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
  html, body, #map { height: 100%; margin: 0; padding: 0; background: #DFF0FF; }
  .leaflet-container { font: 12px/1.4 -apple-system, system-ui, sans-serif; }
  /* The hint sits over the map until the first pin is down, because a map
     with no marker gives no clue that tapping it does anything. */
  #hint {
    position: absolute; z-index: 500; left: 8px; right: 8px; top: 8px;
    padding: 6px 10px; border-radius: 8px; text-align: center;
    background: rgba(255,255,255,0.92); color: #1F1F1F;
    font: 600 12px/1.4 -apple-system, system-ui, sans-serif;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
  }
  /* A carrier's name, riding beside its circle. Permanent rather than on
     hover: a phone has no hover, and a map of unlabelled dots is a puzzle. */
  .carrier-label {
    background: rgba(255,255,255,0.94); border: 0; border-radius: 6px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.12); padding: 2px 6px;
    font: 600 11px/1.3 -apple-system, system-ui, sans-serif; color: #1F1F1F;
  }
  .carrier-label::before { display: none; }
  /* The two ends of the route, named. Same treatment as a carrier label so a
     driver reads one map rather than two conventions. */
  .route-label {
    background: rgba(255,255,255,0.94); border: 0; border-radius: 6px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.12); padding: 2px 6px;
    font: 600 11px/1.3 -apple-system, system-ui, sans-serif; color: #1F1F1F;
  }
  .route-label::before { display: none; }
</style>
</head>
<body>
<div id="map"></div>
${interactive ? '<div id="hint">Tap the map to drop a pin</div>' : ''}
<script>
  (function () {
    var map = L.map('map', {
      center: [${centre[0]}, ${centre[1]}],
      zoom: ${ZOOM},
      zoomControl: true,
      // Wheel zoom inside a sheet hijacks the scroll somebody meant for the
      // sheet. Pinch is untouched, which is how a phone zooms anyway.
      scrollWheelZoom: false,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    var interactive = ${interactive ? 'true' : 'false'};
    var marker = null;
    var hint = document.getElementById('hint');

    /** The carrier circles, by id, so a redraw can replace rather than stack. */
    var pins = {};

    /** The route line and its two end dots, so a redraw replaces them. */
    var routeLayer = null;

    function send(payload) {
      var message = JSON.stringify(payload);

      if (window.ReactNativeWebView) {
        window.ReactNativeWebView.postMessage(message);
      } else if (window.parent !== window) {
        window.parent.postMessage(message, '*');
      }
    }

    function place(lat, lng) {
      if (hint) hint.style.display = 'none';

      if (marker === null) {
        marker = L.marker([lat, lng], { draggable: interactive }).addTo(map);

        if (interactive) {
          marker.on('dragend', function () {
            var at = marker.getLatLng();
            send({ type: 'pick', lat: at.lat, lng: at.lng });
          });
        }

        return;
      }

      marker.setLatLng([lat, lng]);
    }

    // Host -> page. Moving the pin from a search result must not report back
    // as a pick, or the name just chosen would be looked up and overwritten.
    window.__setPin = function (lat, lng, recentre) {
      place(lat, lng);
      if (recentre) map.setView([lat, lng], Math.max(map.getZoom(), 14));
    };

    window.__clearPin = function () {
      if (marker !== null) { marker.remove(); marker = null; }
      if (hint) hint.style.display = '';
    };

    /**
     * The carriers, and which one is chosen.
     *
     * Everything is redrawn rather than diffed: the list is a couple of dozen
     * circles at most, it changes when somebody moves the map or types a
     * search, and a diff would be more code than the thing it saves.
     */
    window.__setMarkers = function (payload) {
      var list = (payload && payload.markers) || [];
      var selected = (payload && payload.selected) || null;
      var fit = !!(payload && payload.fit);

      for (var id in pins) { pins[id].remove(); }
      pins = {};

      if (hint && list.length > 0) hint.style.display = 'none';

      list.forEach(function (item) {
        var chosen = item.id === selected;

        var circle = L.circleMarker([item.lat, item.lng], {
          radius: chosen ? 11 : 8,
          weight: chosen ? 3 : 2,
          color: '#FFFFFF',
          fillColor: chosen ? '#1266F1' : '#5B7A99',
          fillOpacity: 1,
        }).addTo(map);

        circle.bindTooltip(
          item.detail ? item.label + ' · ' + item.detail : item.label,
          { permanent: true, direction: 'right', offset: [8, 0], className: 'carrier-label' }
        );

        // Choosing, not moving. The host owns which one is selected and pushes
        // the answer back down, so the page never decides for itself.
        circle.on('click', function () { send({ type: 'select', id: item.id }); });

        pins[item.id] = circle;
      });

      if (!fit || list.length === 0) return;

      var bounds = L.latLngBounds(list.map(function (item) { return [item.lat, item.lng]; }));
      if (marker !== null) bounds.extend(marker.getLatLng());

      // Padded, because a pin flush against the edge of a small map reads as
      // being off it. Capped, so one carrier does not zoom to a rooftop.
      map.fitBounds(bounds, { padding: [28, 28], maxZoom: 13 });
    };

    /**
     * The road from A to B.
     *
     * Drawn as a casing and a line — a wide pale stroke under a narrow blue
     * one — which is how every map draws a route and the reason one is
     * readable over a busy tile: a single 4px line disappears into a city.
     *
     * A straight-line route is dashed, because it is not a road. The screen says
     * so in words too; the dashes are so nobody reads the map and misses the
     * caption.
     *
     * The ends get dots rather than teardrop pins: the driver's own position is
     * the pin on this map, and two more of those would be three pins with no
     * way to tell which is which.
     */
    window.__setRoute = function (payload) {
      var points = (payload && payload.points) || [];
      var labels = (payload && payload.labels) || null;
      var dashed = !!(payload && payload.dashed);
      var fit = payload ? payload.fit !== false : true;

      if (routeLayer !== null) { routeLayer.remove(); routeLayer = null; }
      if (points.length < 2) return;

      if (hint) hint.style.display = 'none';

      routeLayer = L.layerGroup().addTo(map);

      L.polyline(points, {
        color: '#FFFFFF',
        weight: 8,
        opacity: 0.9,
        lineCap: 'round',
        lineJoin: 'round',
      }).addTo(routeLayer);

      L.polyline(points, {
        color: '#1266F1',
        weight: 4,
        opacity: 1,
        lineCap: 'round',
        lineJoin: 'round',
        dashArray: dashed ? '8 8' : null,
      }).addTo(routeLayer);

      var ends = [
        { at: points[0], colour: '#12805C', name: labels ? labels.from : 'From' },
        { at: points[points.length - 1], colour: '#1266F1', name: labels ? labels.to : 'To' },
      ];

      ends.forEach(function (end) {
        var dot = L.circleMarker(end.at, {
          radius: 7,
          weight: 3,
          color: '#FFFFFF',
          fillColor: end.colour,
          fillOpacity: 1,
        }).addTo(routeLayer);

        if (end.name) {
          dot.bindTooltip(end.name, {
            permanent: true,
            direction: 'right',
            offset: [8, 0],
            className: 'route-label',
          });
        }
      });

      if (!fit) return;

      // The whole line, padded: a route that runs off the edge of a 220px map
      // is a route a driver cannot read. The unit's own pin is included so it
      // is never the one thing off screen.
      var bounds = L.latLngBounds(points);
      if (marker !== null) bounds.extend(marker.getLatLng());

      map.fitBounds(bounds, { padding: [24, 24] });
    };

    // The web build talks to the page through the iframe rather than by
    // injecting script into it, so the same calls arrive as messages.
    window.addEventListener('message', function (event) {
      var data = event.data;
      try { data = typeof data === 'string' ? JSON.parse(data) : data; } catch (e) { return; }
      if (!data || typeof data !== 'object') return;

      if (data.type === 'set') window.__setPin(data.lat, data.lng, data.recentre);
      if (data.type === 'clear') window.__clearPin();
      if (data.type === 'markers') window.__setMarkers(data);
      if (data.type === 'route') window.__setRoute(data);
    });

    if (interactive) {
      map.on('click', function (event) {
        place(event.latlng.lat, event.latlng.lng);
        send({ type: 'pick', lat: event.latlng.lat, lng: event.latlng.lng });
      });
    }

    ${initial ? `place(${initial.lat}, ${initial.lng});` : ''}
    ${route !== null && route.points.length > 1
      ? `window.__setRoute(${JSON.stringify({
        points: route.points,
        labels: options.routeLabels ?? null,
        dashed: route.source === 'straight',
        fit: true,
      })});`
      : ''}
    ${markers.length > 0
      ? `window.__setMarkers(${JSON.stringify({
        markers,
        selected: options.selectedId ?? null,
        fit: true,
      })});`
      : ''}

    send({ type: 'ready' });
  })();
</script>
</body>
</html>`;
}
