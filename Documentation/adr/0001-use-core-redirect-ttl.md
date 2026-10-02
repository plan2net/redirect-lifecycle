# Use the Core redirect TTL as the initial lifetime

The initial lifetime is determined by the Core site setting `redirects.redirectTTL`, accepting site-specific initial lifetimes. Redirects without an unambiguous site use a global `redirectTTL` fallback in the extension settings, initially zero; it does not compete with a known site's TTL, including a site TTL of zero. Zero means unlimited validity, and the initial lifetime uses the Core's calendar-day calculation.
