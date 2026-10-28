export default function Button({
  children,
  variant = "primary",
  type = "button",
  className = "",
  ...props
}) {
  const variantClass =
    variant === "secondary"
      ? "btn btn-secondary"
      : variant === "danger"
        ? "btn btn-danger"
        : "btn btn-primary";

  return (
    <button
      type={type}
      className={`${variantClass} ${className}`.trim()}
      {...props}
    >
      {children}
    </button>
  );
}
