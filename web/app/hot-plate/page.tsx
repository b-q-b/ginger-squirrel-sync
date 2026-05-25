"use client";

import { useState, useMemo, useRef } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiGet, apiSend } from "@/lib/api";
import { useAuthGuard } from "@/lib/auth";
import { TopBar } from "@/components/TopBar";
import type { HotPlateItem, HotPlateCategory, HotPlateColumn, Priority, EnergyLevel } from "@/lib/types";

const COLUMNS: { key: HotPlateColumn; label: string }[] = [
  { key: "Todo", label: "To do" },
  { key: "InProgress", label: "In progress" },
  { key: "Waiting", label: "Waiting" },
  { key: "Done", label: "Done" },
];

const COLUMN_KEY_API: Record<HotPlateColumn, string> = {
  Todo: "todo",
  InProgress: "in_progress",
  Waiting: "waiting",
  Done: "done",
};

const PRIORITY_LABELS: Record<number, string> = { 1: "Low", 2: "Med", 3: "High", 4: "Crit" };
const PRIORITY_CLASSES: Record<number, string> = {
  1: "bg-gray-100 text-gray-600",
  2: "bg-blue-50 text-blue-700",
  3: "bg-amber-50 text-amber-800",
  4: "bg-red-50 text-red-700",
};
const CAT_COLOR_CLASSES: Record<string, string> = {
  blue:   "bg-blue-50 text-blue-700",
  green:  "bg-emerald-50 text-emerald-700",
  purple: "bg-purple-50 text-purple-700",
  orange: "bg-orange-50 text-orange-700",
  amber:  "bg-amber-50 text-amber-800",
  red:    "bg-red-50 text-red-700",
  pink:   "bg-pink-50 text-pink-700",
  cyan:   "bg-cyan-50 text-cyan-700",
};

export default function HotPlatePage() {
  const auth = useAuthGuard();
  const qc = useQueryClient();

  const items = useQuery({ queryKey: ["hot-plate", "items"], queryFn: () => apiGet<HotPlateItem[]>("/api/hot-plate/items"), enabled: auth.authenticated });
  const cats = useQuery({ queryKey: ["hot-plate", "categories"], queryFn: () => apiGet<HotPlateCategory[]>("/api/hot-plate/categories"), enabled: auth.authenticated });

  const [filterCat, setFilterCat] = useState<string>("");
  const [editing, setEditing] = useState<HotPlateItem | "new" | null>(null);
  const dragging = useRef<HotPlateItem | null>(null);
  const [dragOverCol, setDragOverCol] = useState<HotPlateColumn | null>(null);

  const byColumn = useMemo(() => {
    const cols: Record<HotPlateColumn, HotPlateItem[]> = { Todo: [], InProgress: [], Waiting: [], Done: [] };
    for (const i of items.data ?? []) {
      if (filterCat && i.categoryId !== filterCat) continue;
      cols[i.column].push(i);
    }
    for (const k of Object.keys(cols) as HotPlateColumn[]) cols[k].sort((a, b) => a.position - b.position);
    return cols;
  }, [items.data, filterCat]);

  const catsById = useMemo(() => {
    const m = new Map<string, HotPlateCategory>();
    for (const c of cats.data ?? []) m.set(c.id, c);
    return m;
  }, [cats.data]);

  const move = useMutation({
    mutationFn: async ({ id, column }: { id: string; column: HotPlateColumn }) => {
      const maxPos = byColumn[column].reduce((m, i) => Math.max(m, i.position), -1);
      await apiSend("PATCH", `/api/hot-plate/items/${id}`, { columnKey: COLUMN_KEY_API[column], position: maxPos + 1 });
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ["hot-plate", "items"] }),
  });

  if (auth.loading) return <main className="p-8 text-(--color-muted)">Loading…</main>;
  if (!auth.authenticated) return null;

  return (
    <main className="mx-auto max-w-7xl p-6">
      <TopBar title="Hot Plate" subtitle="Personal Kanban — drag cards between columns to update status." />

      <div className="flex gap-2 items-center mb-4 bg-(--color-surface) border border-(--color-border) rounded-xl px-4 py-3">
        <span className="text-xs uppercase tracking-wider text-(--color-muted)">Filter</span>
        <button
          onClick={() => setFilterCat("")}
          className={`px-3 py-1 text-xs rounded-full border ${filterCat === "" ? "bg-(--color-accent) text-white border-(--color-accent)" : "border-(--color-border) text-(--color-muted)"}`}
        >
          All
        </button>
        {(cats.data ?? []).map((c) => (
          <button
            key={c.id}
            onClick={() => setFilterCat(c.id === filterCat ? "" : c.id)}
            className={`px-3 py-1 text-xs rounded-full border ${filterCat === c.id ? "bg-(--color-accent) text-white border-(--color-accent)" : "border-(--color-border) text-(--color-muted) hover:border-(--color-accent)"}`}
          >
            {c.name}
          </button>
        ))}
        <button
          onClick={() => setEditing("new")}
          className="ml-auto px-4 py-1.5 rounded-lg bg-(--color-accent) text-white text-sm font-medium hover:bg-(--color-accent-hover)"
        >
          + New task
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
        {COLUMNS.map(({ key, label }) => (
          <div
            key={key}
            onDragOver={(e) => { e.preventDefault(); setDragOverCol(key); }}
            onDragLeave={() => setDragOverCol((c) => (c === key ? null : c))}
            onDrop={(e) => {
              e.preventDefault();
              setDragOverCol(null);
              if (dragging.current && dragging.current.column !== key) {
                move.mutate({ id: dragging.current.id, column: key });
              }
              dragging.current = null;
            }}
            className={`rounded-xl border bg-(--color-surface) p-2.5 min-h-[200px] flex flex-col gap-2 ${dragOverCol === key ? "border-(--color-accent) bg-(--color-accent-soft)" : "border-(--color-border)"}`}
          >
            <div className="flex justify-between items-baseline px-1 pb-1 border-b border-(--color-border)">
              <span className="text-xs uppercase tracking-wider text-(--color-muted) font-medium">{label}</span>
              <span className="text-[10px] bg-(--color-bg) border border-(--color-border) px-1.5 rounded-full">{byColumn[key].length}</span>
            </div>
            {byColumn[key].length === 0 ? (
              <div className="text-(--color-muted) text-xs text-center py-4 italic">—</div>
            ) : (
              byColumn[key].map((i) => (
                <Card key={i.id} item={i} category={i.categoryId ? catsById.get(i.categoryId) : undefined}
                  onClick={() => setEditing(i)}
                  onDragStart={() => { dragging.current = i; }}
                />
              ))
            )}
          </div>
        ))}
      </div>

      {editing !== null && (
        <ItemModal
          item={editing === "new" ? null : editing}
          categories={cats.data ?? []}
          onClose={() => setEditing(null)}
          onSaved={() => qc.invalidateQueries({ queryKey: ["hot-plate", "items"] })}
        />
      )}
    </main>
  );
}

function Card({ item, category, onClick, onDragStart }: { item: HotPlateItem; category?: HotPlateCategory; onClick: () => void; onDragStart: () => void }) {
  const due = item.dueDate ? new Date(item.dueDate + "T00:00:00") : null;
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const overdue = due && due < today;
  const dueClass = overdue ? "text-(--color-danger) font-semibold" : "text-(--color-muted)";

  return (
    <div
      draggable
      onClick={onClick}
      onDragStart={onDragStart}
      className="bg-(--color-bg) border border-(--color-border) rounded-lg px-3 py-2 cursor-grab hover:shadow-md transition-shadow active:cursor-grabbing"
    >
      <div className="font-medium text-sm mb-1">{item.title}</div>
      {item.description && <div className="text-xs text-(--color-muted) mb-2 line-clamp-2">{item.description}</div>}
      <div className="flex flex-wrap gap-1.5 items-center text-[10px]">
        <span className={`px-2 py-0.5 rounded-full font-medium ${PRIORITY_CLASSES[item.priority]}`}>{PRIORITY_LABELS[item.priority]}</span>
        {category && <span className={`px-2 py-0.5 rounded-full font-medium ${CAT_COLOR_CLASSES[category.color] ?? "bg-gray-100"}`}>{category.name}</span>}
        {due && <span className={dueClass}>{due.toLocaleDateString(undefined, { month: "short", day: "numeric" })}</span>}
        {item.energyLevel && <span className="text-(--color-muted)">{item.energyLevel.toLowerCase()}</span>}
      </div>
    </div>
  );
}

function ItemModal({ item, categories, onClose, onSaved }: {
  item: HotPlateItem | null;
  categories: HotPlateCategory[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const isNew = item === null;
  const [title, setTitle] = useState(item?.title ?? "");
  const [description, setDescription] = useState(item?.description ?? "");
  const [column, setColumn] = useState<HotPlateColumn>(item?.column ?? "Todo");
  const [priority, setPriority] = useState<Priority>(item?.priority ?? 2);
  const [dueDate, setDueDate] = useState(item?.dueDate ?? "");
  const [categoryId, setCategoryId] = useState<string>(item?.categoryId ?? "");
  const [energy, setEnergy] = useState<EnergyLevel | "">(item?.energyLevel ?? "");
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function save() {
    if (!title.trim()) { setErr("Title required"); return; }
    setSaving(true); setErr(null);
    try {
      const payload = {
        title,
        description: description || null,
        columnKey: COLUMN_KEY_API[column],
        priority,
        dueDate: dueDate || null,
        categoryId: categoryId || null,
        energyLevel: energy || null,
      };
      if (isNew) {
        await apiSend("POST", "/api/hot-plate/items", payload);
      } else {
        await apiSend("PATCH", `/api/hot-plate/items/${item.id}`, payload);
      }
      onSaved();
      onClose();
    } catch (e) {
      setErr((e as Error).message);
    } finally { setSaving(false); }
  }

  async function remove() {
    if (!item) return;
    if (!confirm("Delete this task?")) return;
    setSaving(true);
    try { await apiSend("DELETE", `/api/hot-plate/items/${item.id}`); onSaved(); onClose(); }
    catch (e) { setErr((e as Error).message); }
    finally { setSaving(false); }
  }

  return (
    <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div className="bg-(--color-surface) rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div className="px-6 py-4 border-b border-(--color-border)">
          <h2 className="text-lg font-bold">{isNew ? "New task" : "Edit task"}</h2>
        </div>
        <div className="p-6 space-y-4">
          <Field label="Title">
            <input
              className="w-full px-3 py-2 border border-(--color-border) rounded-lg bg-(--color-bg) focus:outline-none focus:ring-2 focus:ring-(--color-accent)"
              value={title} onChange={(e) => setTitle(e.target.value)} autoFocus
            />
          </Field>
          <Field label="Description">
            <textarea
              className="w-full px-3 py-2 border border-(--color-border) rounded-lg bg-(--color-bg) min-h-[80px] focus:outline-none focus:ring-2 focus:ring-(--color-accent)"
              value={description} onChange={(e) => setDescription(e.target.value)}
            />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Column">
              <select className="w-full px-3 py-2 border border-(--color-border) rounded-lg bg-(--color-bg)" value={column} onChange={(e) => setColumn(e.target.value as HotPlateColumn)}>
                {COLUMNS.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
              </select>
            </Field>
            <Field label="Category">
              <select className="w-full px-3 py-2 border border-(--color-border) rounded-lg bg-(--color-bg)" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
                <option value="">— none —</option>
                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <Field label="Priority">
              <div className="flex gap-1">
                {([1, 2, 3, 4] as Priority[]).map((p) => (
                  <button key={p} type="button" onClick={() => setPriority(p)}
                    className={`flex-1 py-1.5 text-xs rounded border ${priority === p ? "bg-(--color-accent) text-white border-(--color-accent)" : "border-(--color-border) text-(--color-muted)"}`}>
                    {PRIORITY_LABELS[p]}
                  </button>
                ))}
              </div>
            </Field>
            <Field label="Due date">
              <input type="date" className="w-full px-3 py-2 border border-(--color-border) rounded-lg bg-(--color-bg)" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
            </Field>
          </div>
          <Field label="Energy required">
            <div className="flex gap-1">
              {(["", "Quick", "Social", "Deep", "Creative"] as const).map((e) => (
                <button key={e || "any"} type="button" onClick={() => setEnergy(e as EnergyLevel | "")}
                  className={`flex-1 py-1.5 text-xs rounded border ${energy === e ? "bg-(--color-accent) text-white border-(--color-accent)" : "border-(--color-border) text-(--color-muted)"}`}>
                  {e || "Any"}
                </button>
              ))}
            </div>
          </Field>
          {err && <div className="text-sm text-(--color-danger) bg-red-50 border border-red-200 rounded px-3 py-2">{err}</div>}
        </div>
        <div className="px-6 py-4 border-t border-(--color-border) flex justify-between items-center">
          {item ? (
            <button onClick={remove} className="text-sm text-(--color-danger) px-3 py-1.5 rounded border border-(--color-border) hover:border-(--color-danger)">Delete</button>
          ) : <span />}
          <div className="flex gap-2">
            <button onClick={onClose} className="px-4 py-1.5 text-sm text-(--color-muted) rounded border border-(--color-border)">Cancel</button>
            <button onClick={save} disabled={saving} className="px-4 py-1.5 text-sm bg-(--color-accent) text-white rounded font-medium hover:bg-(--color-accent-hover) disabled:opacity-50">
              {saving ? "Saving…" : "Save"}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <label className="block text-xs uppercase tracking-wider text-(--color-muted) mb-1.5 font-medium">{label}</label>
      {children}
    </div>
  );
}
