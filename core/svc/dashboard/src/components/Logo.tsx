export default function Logo({ size = 30 }: { size?: number }): JSX.Element {
  return (
    <svg className="logo" width={size} height={size} viewBox="0 0 32 32" fill="none" aria-hidden>
      <defs>
        <linearGradient id="nvx-g" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
          <stop stopColor="#6d5efc" />
          <stop offset="0.5" stopColor="#a855f7" />
          <stop offset="1" stopColor="#22d3ee" />
        </linearGradient>
      </defs>
      <rect x="1" y="1" width="30" height="30" rx="9" fill="url(#nvx-g)" opacity="0.16" />
      <rect x="1" y="1" width="30" height="30" rx="9" stroke="url(#nvx-g)" strokeWidth="1.4" />
      <path
        d="M9 23V9l14 14V9"
        stroke="url(#nvx-g)"
        strokeWidth="3.1"
        fill="none"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}
