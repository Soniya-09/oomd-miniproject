export default function Logo({ size = 28 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 32 32" aria-hidden="true" className="logo">
      <circle cx="16" cy="16" r="14" fill="var(--accent)" />
      <circle cx="16" cy="16" r="10" fill="none" stroke="var(--accent-ink)" strokeOpacity=".35" strokeWidth="1" />
      <path d="M16 7l2.2 6.8L25 16l-6.8 2.2L16 25l-2.2-6.8L7 16l6.8-2.2z" fill="var(--accent-ink)" />
    </svg>
  );
}
