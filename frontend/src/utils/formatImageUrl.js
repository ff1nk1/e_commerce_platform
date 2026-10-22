const PLACEHOLDER_IMAGE =
    "data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='150' height='150' viewBox='0 0 150 150'%3E%3Crect width='100%25' height='100%25' fill='%23f3f4f6'/%3E%3Ctext x='50%25' y='50%25' dominant-baseline='middle' text-anchor='middle' font-family='sans-serif' font-size='14' fill='%239ca3af'%3E%D0%9D%D0%B5%D1%82%20%D1%84%D0%BE%D1%82%D0%BE%3C/text%3E%3C/svg%3E"

/**
 * Normalize product image URLs for the browser (same-origin /storage proxy).
 */
export function formatImageUrl(url) {
    if (!url) return PLACEHOLDER_IMAGE
    if (url.startsWith('data:')) return url

    // Legacy internal Docker hostnames accidentally stored in DB
    if (url.includes('minio:9000') || url.includes('catalog_service_minio:9000')) {
        const path = url.replace(/^https?:\/\/[^/]+/, '')
        if (path.startsWith('/storage')) {
            return path
        }
        const normalizedPath = path.startsWith('/') ? path : `/${path}`
        return `/storage${normalizedPath}`
    }

    if (url.startsWith('http://') || url.startsWith('https://')) {
        try {
            const parsed = new URL(url)
            if (parsed.pathname.startsWith('/storage/')) {
                return parsed.pathname + parsed.search
            }
        } catch {
            // keep absolute URL
        }
        return url
    }

    const cleanPath = url.startsWith('/') ? url : `/${url}`
    return cleanPath
}

export { PLACEHOLDER_IMAGE }
