/**
 * Shared page layout: full-height page surface + card + optional header.
 */
export default function PageShell({
  title,
  subtitle,
  headerExtra = null,
  children,
  className = '',
  cardClassName = '',
}) {
  return (
    <div className={`page ${className}`.trim()}>
      <div className={`page-card ${cardClassName}`.trim()}>
        {(title || subtitle || headerExtra) && (
          <div className="page-header">
            <div>
              {title ? <h1>{title}</h1> : null}
              {subtitle ? <p>{subtitle}</p> : null}
            </div>
            {headerExtra}
          </div>
        )}
        {children}
      </div>
    </div>
  )
}
