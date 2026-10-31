/**
 * Chart theme for Highcharts JS: the charts wear the page's own theme.
 *
 * Every colour a chart draws in text, axes, grid and tooltip is read from the
 * shell's CSS custom properties (202-css/p202-theme.css), so a chart on a dark
 * page is dark and one on a light page is light, with no second list of
 * colours to keep in step. The chart background is transparent: the panel
 * behind it is the surface. (The theme this replaced, after Sand-Signika,
 * painted a sand texture and black titles into every chart whatever the page
 * was, which on a dark page left a bright slab with the page's own text
 * colours missing from it.)
 *
 * The series colours are one categorical order stepped for each surface —
 * the same hues in the same order in both modes, so a line keeps its colour
 * when the theme changes. Both columns were run through the dataviz palette
 * validator against the shell's surfaces (#ffffff and #20252c): every check
 * passes in dark; in light three slots sit below 3:1 against white, which the
 * charts answer with a legend and labelled points.
 *
 * The theme switch (202-js/p202-chrome.js) changes data-bs-theme on <html>;
 * charts already drawn are redrawn from their own options under the new
 * theme when it does.
 *
 * Charts use the site's own font. The original theme fetched Signika from
 * Google Fonts at runtime, which put every chart page's reader on a third-party
 * request the install does not control; Lato ships with this repository and is
 * what the rest of the interface uses, so the charts now match it.
 */
(function () {
   var PALETTE = {
      light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'],
      dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767']
   };

   function mode() {
      return document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
   }

   function token(name, fallback) {
      var value = window.getComputedStyle(document.documentElement).getPropertyValue(name).trim();
      return value !== '' ? value : fallback;
   }

   function themeOptions() {
      var dark = mode() === 'dark';
      var ink = token('--bs-body-color', dark ? '#dfe3e8' : '#1f2328');
      var muted = token('--bs-secondary-color', dark ? '#9aa3ad' : '#6b7280');
      var line = token('--bs-border-color', dark ? '#383f49' : '#e7e8ea');
      var surface = token('--p202-surface', dark ? '#20252c' : '#ffffff');
      var axis = { lineColor: line, tickColor: line, gridLineColor: line, labels: { style: { color: muted } }, title: { style: { color: muted } } };
      return {
         colors: PALETTE[dark ? 'dark' : 'light'],
         chart: {
            backgroundColor: 'transparent',
            plotBorderColor: line,
            style: { fontFamily: "Lato, 'Helvetica Neue', Helvetica, Arial, sans-serif" }
         },
         title: { style: { color: ink, fontSize: '15px', fontWeight: '700' } },
         subtitle: { style: { color: muted } },
         legend: {
            itemStyle: { color: ink, fontSize: '13px', fontWeight: '600' },
            itemHoverStyle: { color: ink },
            itemHiddenStyle: { color: muted }
         },
         // Values are read on hover, not printed on every point: one shared
         // tooltip for the column under the pointer, marked by a crosshair.
         // Both live here rather than in a chart's own options, because the
         // Overview's hour/day switch rebuilds its xAxis from the categories
         // alone (202-js/p202-overview.js) and would drop them.
         xAxis: Highcharts.merge(axis, { crosshair: { color: line, width: 1 } }),
         yAxis: axis,
         tooltip: {
            shared: true,
            backgroundColor: surface,
            borderColor: line,
            borderWidth: 1,
            borderRadius: 8,
            shadow: false,
            style: { color: ink }
         },
         plotOptions: {
            series: {
               shadow: false,
               lineWidth: 2,
               // A point's value wears text colour, never the series colour,
               // and no halo: the outline Highcharts adds by default reads as
               // a grey box on a dark surface.
               dataLabels: { color: muted, style: { textOutline: 'none', fontWeight: '600' } },
               // The ring around a marker is the surface, so a marker that
               // sits on another line stays separate from it.
               marker: { lineColor: surface, lineWidth: 1 }
            }
         }
      };
   }

   Highcharts.setOptions(themeOptions());

   // Accessibility is off by default (accessibility.js is not bundled for performance).
   // To enable, include the Highcharts accessibility module script before this file.
   if (!(Highcharts.A11yModule || Highcharts.AccessibilityComponent)) {
      Highcharts.setOptions({ accessibility: { enabled: false } });
   }

   // On a theme change, set the new defaults and redraw every live chart from
   // the options it was created with; a chart drawn into a container that
   // already holds one replaces it.
   var drawnIn = mode();
   new MutationObserver(function () {
      if (mode() === drawnIn) {
         return;
      }
      drawnIn = mode();
      Highcharts.setOptions(themeOptions());
      Highcharts.charts.slice().forEach(function (chart) {
         if (chart && chart.renderTo && chart.renderTo.isConnected) {
            Highcharts.chart(chart.renderTo, chart.userOptions);
         }
      });
   }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
}());
