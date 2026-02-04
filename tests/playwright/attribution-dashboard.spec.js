const { test, expect } = require('@playwright/test');

test('renders analytics metrics, chart, and anomaly alerts', async ({ page, baseURL }) => {
  const origin = baseURL || 'http://localhost:8080';

  await page.route('**/api/v2/attribution/models', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        error: false,
        data: [
          {
            model_id: 1,
            user_id: 1,
            name: 'Weighted Model',
            slug: 'weighted-model',
            type: 'position_based',
            is_active: true,
            is_default: true,
            created_at: 0,
            updated_at: 0,
            weighting_config: {}
          }
        ]
      })
    });
  });

  await page.route('**/api/v2/attribution/metrics**', (route) => {
    const nowHour = Math.floor(Date.now() / 1000);
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        error: false,
        data: {
          totals: { revenue: 180.5, conversions: 9, clicks: 45, cost: 60, roi: 200 },
          snapshots: [
            {
              snapshot_id: 1,
              date_hour: nowHour - 3600,
              attributed_clicks: 15,
              attributed_conversions: 3,
              attributed_revenue: 60,
              attributed_cost: 20
            },
            {
              snapshot_id: 2,
              date_hour: nowHour,
              attributed_clicks: 30,
              attributed_conversions: 6,
              attributed_revenue: 120.5,
              attributed_cost: 40
            }
          ],
          touchpoint_mix: [
            { bucket: 'first_touch', label: 'First Touch', total_credit: 0.4, touch_count: 5, share: 40 },
            { bucket: 'last_touch', label: 'Last Touch', total_credit: 0.6, touch_count: 7, share: 60 }
          ],
          anomalies: [
            {
              metric: 'revenue',
              severity: 'warning',
              direction: 'up',
              delta_percent: 48.5,
              message: 'Revenue jumped by 48.50% compared to baseline.'
            }
          ]
        }
      })
    });
  });

  const apiBase = `${origin}/api/v2/attribution`;
  const scriptUrl = new URL('/202-js/attribution-dashboard.js', origin).toString();

  await page.setContent(`
    <!DOCTYPE html>
    <html>
      <head>
        <meta charset="utf-8" />
        <title>Attribution Dashboard Fixture</title>
      </head>
      <body>
        <div data-attribution-dashboard data-api-base="${apiBase}">
          <div class="panel panel-default dashboard-panel">
            <div class="panel-heading clearfix">
              <h3 class="panel-title pull-left">Fixture Dashboard</h3>
              <span class="pull-right text-muted" data-role="last-refreshed">&nbsp;</span>
            </div>
            <div class="panel-body">
              <div class="row control-row">
                <div class="col-sm-4">
                  <label>Model</label>
                  <select data-role="model-select"></select>
                  <span class="help-block" data-role="model-helper">Loading…</span>
                </div>
                <div class="col-sm-4">
                  <label>Scope</label>
                  <select data-role="scope-select">
                    <option value="global">Global</option>
                  </select>
                </div>
                <div class="col-sm-4">
                  <input data-role="scope-id" />
                </div>
              </div>
              <div class="row" data-role="kpi-container">
                <div class="kpi-card" data-role="kpi" data-kpi="revenue"><span class="label">Revenue</span><span class="metric">$0.00</span></div>
                <div class="kpi-card" data-role="kpi" data-kpi="conversions"><span class="label">Conversions</span><span class="metric">0</span></div>
                <div class="kpi-card" data-role="kpi" data-kpi="clicks"><span class="label">Clicks</span><span class="metric">0</span></div>
                <div class="kpi-card" data-role="kpi" data-kpi="roi"><span class="label">ROI</span><span class="metric">–</span></div>
              </div>
              <div class="row">
                <div class="col-md-8">
                  <div id="attribution-chart" style="height:300px"></div>
                </div>
                <div class="col-md-4">
                  <ul data-role="touchpoint-mix"><li>Waiting…</li></ul>
                </div>
              </div>
              <div data-role="anomaly-banner"></div>
              <div data-role="analytics-disabled" style="display:none;"></div>
              <div data-role="empty-state" style="display:none;"></div>
            </div>
          </div>
        </div>
        <script>
          window.Highcharts = {
            chart: function (containerId) {
              var container = document.getElementById(containerId);
              if (container) {
                container.innerHTML = '<svg data-testid="chart"></svg>';
              }
              return {
                series: [{ setData: function () {} }, { setData: function () {} }],
                redraw: function () {}
              };
            }
          };
        </script>
        <script src="${scriptUrl}"></script>
      </body>
    </html>
  `);

  await expect(page.locator('[data-role="kpi"][data-kpi="revenue"] .metric')).toContainText('$');
  await expect(page.locator('#attribution-chart svg')).toBeVisible();
  await expect(page.locator('[data-role="anomaly-alert"]')).toHaveCount(1);
});
