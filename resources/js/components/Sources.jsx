export default function Sources({ sources }) {
    if (!sources || sources.length === 0) return null;

    return (
        <div className="mt-3 space-y-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Sources</p>
            {sources.map((source) => (
                <details key={source.rank} className="rounded-md border border-slate-200 bg-slate-50 p-2 text-xs">
                    <summary className="cursor-pointer select-none font-medium text-slate-700">
                        #{source.rank} · {source.filename} · chunk {source.chunk_index}
                        {' · '}
                        similarity {Number(source.similarity).toFixed(3)}
                    </summary>
                    <p className="mt-2 whitespace-pre-wrap text-slate-600">{source.content}</p>
                </details>
            ))}
        </div>
    );
}
