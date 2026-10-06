# Use the Core redirect TTL as the initial lifetime

The initial lifetime is determined by the Core site setting `redirects.redirectTTL`, accepting site-specific initial lifetimes. Redirects without an unambiguous site, or whose site TTL is missing or `null`, use a global `redirectTTL` fallback in the extension settings, initially zero. An explicit site TTL of zero remains authoritative. Zero means unlimited validity, and the initial lifetime uses the Core's calendar-day calculation.
