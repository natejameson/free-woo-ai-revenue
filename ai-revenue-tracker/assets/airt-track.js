/* AI Revenue Tracker: detects shoppers arriving from AI assistants, even on cached pages. */
(function () {
	var c = window.airtCfg;
	if (!c || !c.a) { return; }
	if (typeof window.wp_has_consent === 'function' && !window.wp_has_consent('statistics')) { return; }

	var src = null, k, i;
	var utm = '';
	try { utm = (new URLSearchParams(window.location.search).get('utm_source') || '').toLowerCase().trim(); } catch (e) {}

	if (utm) {
		for (k in c.a) {
			if (c.a[k].u.indexOf(utm) > -1) { src = k; break; }
		}
	}

	if (!src && document.referrer) {
		try {
			var host = new URL(document.referrer).hostname.toLowerCase().replace(/^www\./, '');
			var own = window.location.hostname.toLowerCase().replace(/^www\./, '');
			if (host && host !== own) {
				for (k in c.a) {
					for (i = 0; i < c.a[k].d.length; i++) {
						var d = c.a[k].d[i];
						if (host === d || host.slice(-(d.length + 1)) === '.' + d) { src = k; break; }
					}
					if (src) { break; }
				}
			}
		} catch (e) {}
	}

	if (!src) { return; }

	var existing = null;
	var m = document.cookie.match(/(?:^|;\s*)airt_src=([^;]*)/);
	if (m) { try { existing = JSON.parse(decodeURIComponent(m[1].replace(/\+/g, ' '))); } catch (e) {} }

	if (!existing || existing.s !== src) {
		var val = JSON.stringify({ s: src, lp: window.location.pathname.slice(0, 200), ts: Math.floor(Date.now() / 1000) });
		document.cookie = 'airt_src=' + encodeURIComponent(val) + ';path=/;max-age=' + ((c.days || 30) * 86400) +
			';SameSite=Lax' + (window.location.protocol === 'https:' ? ';Secure' : '');
	}

	if (!c.visits || !c.rest) { return; }
	var body = JSON.stringify({ source: src, path: window.location.pathname });
	try {
		if (navigator.sendBeacon && navigator.sendBeacon(c.rest, new Blob([body], { type: 'application/json' }))) { return; }
	} catch (e) {}
	try {
		fetch(c.rest, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'omit' });
	} catch (e) {}
})();
