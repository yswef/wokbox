// Run ONLY against an isolated, representative test deployment. No orders are created.
import http from 'k6/http';
import { check, sleep } from 'k6';

const base = (__ENV.BASE_URL || '').replace(/\/$/, '');
const users = Number(__ENV.VUS || 500);
if (!/^https?:\/\//.test(base) || ![500, 1000, 3000].includes(users)) {
  throw new Error('Set BASE_URL to your isolated test deployment and VUS to 500, 1000 or 3000.');
}
export const options = {
  stages: [
    { duration: '2m', target: users },
    { duration: '5m', target: users },
    { duration: '1m', target: 0 },
  ],
  thresholds: { http_req_failed: ['rate<0.01'], http_req_duration: ['p(95)<2000'], checks: ['rate>0.99'] },
};
export default function () {
  const page = http.get(base + '/', { tags: { endpoint: 'store' } });
  check(page, { 'store returns 200': r => r.status === 200 && r.body.includes('id="track-form"') });
  const menu = http.get(base + '/api/public.php?action=menu', { tags: { endpoint: 'menu' } });
  check(menu, { 'menu succeeds': r => {
    try { return r.status === 200 && r.json('ok') === true; } catch (_) { return false; }
  } });
  sleep(1 + Math.random() * 2);
}
