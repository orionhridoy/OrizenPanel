/* Minimal inline stroke icons (currentColor). */
const s = { width: 18, height: 18, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };

export const IconOverview = (): JSX.Element => (<svg {...s}><rect x="3" y="3" width="7" height="9" rx="1.5" /><rect x="14" y="3" width="7" height="5" rx="1.5" /><rect x="14" y="12" width="7" height="9" rx="1.5" /><rect x="3" y="16" width="7" height="5" rx="1.5" /></svg>);
export const IconInvoice = (): JSX.Element => (<svg {...s}><path d="M6 2h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z" /><path d="M14 2v6h6M8 13h8M8 17h5" /></svg>);
export const IconWithdraw = (): JSX.Element => (<svg {...s}><path d="M12 19V5M5 12l7 7 7-7" /></svg>);
export const IconKey = (): JSX.Element => (<svg {...s}><circle cx="7.5" cy="15.5" r="4.5" /><path d="M10.5 12.5 20 3M17 6l2 2M14 9l2 2" /></svg>);
export const IconWebhook = (): JSX.Element => (<svg {...s}><circle cx="12" cy="7" r="3" /><path d="M10.5 9.5 7 16m0 0a3 3 0 1 0 2.7 1.7M14 16h3a3 3 0 1 1-.8 5.9M14.5 9.5 18 16" /></svg>);
export const IconStore = (): JSX.Element => (<svg {...s}><path d="M4 9h16l-1-5H5L4 9Z" /><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9" /><path d="M9 20v-6h6v6" /></svg>);
export const IconSettings = (): JSX.Element => (<svg {...s}><circle cx="12" cy="12" r="3" /><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1" /></svg>);
export const IconAdmin = (): JSX.Element => (<svg {...s}><path d="M12 2 4 5v6c0 5 3.4 8.5 8 11 4.6-2.5 8-6 8-11V5l-8-3Z" /><path d="m9 12 2 2 4-4" /></svg>);
export const IconWallet = (): JSX.Element => (<svg {...s}><rect x="3" y="6" width="18" height="13" rx="2.5" /><path d="M3 10h18M16 14h2" /></svg>);
export const IconLink = (): JSX.Element => (<svg {...s}><path d="M10 14a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.2 1.1" /><path d="M14 10a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.2-1.1" /></svg>);
export const IconChart = (): JSX.Element => (<svg {...s}><path d="M3 21h18" /><rect x="5" y="12" width="3.4" height="6" rx="1" /><rect x="10.3" y="7" width="3.4" height="11" rx="1" /><rect x="15.6" y="3" width="3.4" height="15" rx="1" /></svg>);
export const IconConnect = (): JSX.Element => (<svg {...s}><path d="M12 3v4M12 17v4M3 12h4M17 12h4" /><circle cx="12" cy="12" r="4" /><path d="m5.6 5.6 2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1" /></svg>);
export const IconCode = (): JSX.Element => (<svg {...s}><path d="m8 8-4 4 4 4M16 8l4 4-4 4M13 5l-2 14" /></svg>);
export const IconBook = (): JSX.Element => (<svg {...s}><path d="M4 5a2 2 0 0 1 2-2h9v16H6a2 2 0 0 0-2 2V5Z" /><path d="M15 3h3a1 1 0 0 1 1 1v15H6" /><path d="M8 7h4M8 11h4" /></svg>);
export const IconSetup = (): JSX.Element => (<svg {...s}><path d="M12 3c3.5 1.5 5 4.5 5 8 0 2-1 3.5-2 4.5H9c-1-1-2-2.5-2-4.5 0-3.5 1.5-6.5 5-8Z" /><circle cx="12" cy="9.5" r="1.6" /><path d="M9 19c0 1 1 2 3 2s3-1 3-2M7.5 15.5 5 18M16.5 15.5 19 18" /></svg>);
