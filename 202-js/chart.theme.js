/**
 * Chart theme for Highcharts JS, after the Sand-Signika theme by Torstein Honsi.
 *
 * Charts use the site's own font. The original theme fetched Signika from
 * Google Fonts at runtime, which put every chart page's reader on a third-party
 * request the install does not control; Lato ships with this repository and is
 * what the rest of the interface uses, so the charts now match it.
 */

// Add the background image to the container
Highcharts.wrap(Highcharts.Chart.prototype, 'getContainer', function (proceed) {
   proceed.call(this);
   this.container.style.background = 'url('+ location.protocol+'//'+location.hostname+(location.port ? ':'+location.port: '') +'/202-img/sand.png)';
});


Highcharts.theme = {
   colors: ["#3498db", "#1abc9c", "#e74c3c", "#8e44ad", "#2c3e50", "#f1c40f", "#7f8c8d",
      "#55BF3B", "#DF5353", "#7798BF", "#aaeeee"],
   chart: {
      backgroundColor: null,
      style: {
         fontFamily: "Lato, 'Helvetica Neue', Helvetica, Arial, sans-serif"
      }
   },
   title: {
      style: {
         color: 'black',
         fontSize: '16px',
         fontWeight: 'bold'
      }
   },
   subtitle: {
      style: {
         color: 'black'
      }
   },
   tooltip: {
      borderWidth: 0
   },
   legend: {
      itemStyle: {
         fontWeight: 'bold',
         fontSize: '13px'
      }
   },
   xAxis: {
      labels: {
         style: {
            color: '#6e6e70'
         }
      }
   },
   yAxis: {
      labels: {
         style: {
            color: '#6e6e70'
         }
      }
   },
   plotOptions: {
      series: {
         shadow: true
      },
      candlestick: {
         lineColor: '#404048'
      },
      map: {
         shadow: false
      }
   },

   // Highstock specific
   navigator: {
      xAxis: {
         gridLineColor: '#D0D0D8'
      }
   },
   rangeSelector: {
      buttonTheme: {
         fill: 'white',
         stroke: '#C0C0C8',
         'stroke-width': 1,
         states: {
            select: {
               fill: '#D0D0D8'
            }
         }
      }
   },
   scrollbar: {
      trackBorderColor: '#C0C0C8'
   },

   // General
   background2: '#E0E0E8'
   
};

// Apply the theme
Highcharts.setOptions(Highcharts.theme);

// Accessibility is off by default (accessibility.js is not bundled for performance).
// To enable, include the Highcharts accessibility module script before this file.
if (!(Highcharts.A11yModule || Highcharts.AccessibilityComponent)) {
	Highcharts.setOptions({ accessibility: { enabled: false } });
}