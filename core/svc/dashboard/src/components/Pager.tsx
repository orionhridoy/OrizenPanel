/**
 * Shared pagination control. Works with or without a known total:
 * when total is undefined, "Next" stays enabled while the current page is full.
 */
export default function Pager({
  offset,
  limit,
  count,
  total,
  onPage,
}: {
  offset: number;
  limit: number;
  /** rows on the current page */
  count: number;
  total?: number;
  onPage: (nextOffset: number) => void;
}): JSX.Element | null {
  const hasPrev = offset > 0;
  const hasNext = total !== undefined ? offset + count < total : count === limit;
  if (!hasPrev && !hasNext) return null;

  const from = count === 0 ? 0 : offset + 1;
  const to = offset + count;
  const label = total !== undefined ? `${from}-${to} of ${total}` : `${from}-${to}`;

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 14 }}>
      <button className="secondary" disabled={!hasPrev} onClick={() => onPage(Math.max(0, offset - limit))}>
        ‹ Prev
      </button>
      <span className="muted" style={{ fontSize: 13 }}>{label}</span>
      <button className="secondary" disabled={!hasNext} onClick={() => onPage(offset + limit)}>
        Next ›
      </button>
    </div>
  );
}
