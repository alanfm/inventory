import { Input } from "@starterkit/module-kit";
import { useEffect, useRef, useState } from "react";

type Option = { value: string; label: string };

function normalize(value: string) {
  return value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase();
}

export function VariantSelect({
  options,
  id = "variantId",
  required,
  "aria-describedby": describedBy,
}: {
  options: Option[];
  id?: string;
  required?: boolean;
  "aria-describedby"?: string;
}) {
  const [selected, setSelected] = useState<Option | null>(null);
  const [query, setQuery] = useState("");
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const filtered = options.filter((option) =>
    normalize(option.label).includes(normalize(query.trim())),
  );
  const activeIndex = Math.min(active, filtered.length - 1);

  useEffect(() => {
    inputRef.current?.setCustomValidity(
      required && !selected ? "Selecione uma variante da lista." : "",
    );
  }, [required, selected]);

  useEffect(() => {
    if (open) {
      listRef.current?.children[activeIndex]?.scrollIntoView?.({
        block: "nearest",
      });
    }
  }, [activeIndex, open]);

  function choose(option: Option) {
    setSelected(option);
    setQuery("");
    setOpen(false);
  }

  return (
    <div className="relative">
      <input type="hidden" name="variantId" value={selected?.value ?? ""} />
      <Input
        ref={inputRef}
        id={id}
        role="combobox"
        aria-expanded={open}
        aria-controls={`${id}-options`}
        aria-autocomplete="list"
        aria-describedby={describedBy}
        aria-activedescendant={
          open && activeIndex >= 0 ? `${id}-option-${activeIndex}` : undefined
        }
        autoComplete="off"
        required={required}
        placeholder="Pesquisar e selecionar variante…"
        value={selected?.label ?? query}
        onFocus={() => {
          setOpen(true);
          setActive(0);
        }}
        onBlur={() => setOpen(false)}
        onChange={(event) => {
          setSelected(null);
          setQuery(event.target.value);
          setActive(0);
          setOpen(true);
        }}
        onKeyDown={(event) => {
          if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();
            setOpen(true);
            setActive(
              !open
                ? 0
                : Math.max(
                    0,
                    Math.min(
                      filtered.length - 1,
                      activeIndex + (event.key === "ArrowDown" ? 1 : -1),
                    ),
                  ),
            );
          } else if (event.key === "Enter" && open) {
            event.preventDefault();
            if (filtered[activeIndex]) choose(filtered[activeIndex]);
          } else if (event.key === "Escape" && open) {
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
          }
        }}
      />
      {open && (
        <div className="absolute z-20 mt-1 w-full rounded-md border border-line-strong bg-surface shadow-lg">
          <ul
            ref={listRef}
            id={`${id}-options`}
            role="listbox"
            aria-label="Variantes"
            className="max-h-60 overflow-y-auto py-1"
          >
            {filtered.map((option, index) => (
              <li
                key={option.value}
                id={`${id}-option-${index}`}
                role="option"
                aria-selected={selected?.value === option.value}
                className={`cursor-pointer px-3 py-2 text-sm text-ink ${index === activeIndex ? "bg-subtle" : ""}`}
                onMouseDown={(event) => event.preventDefault()}
                onMouseEnter={() => setActive(index)}
                onClick={() => choose(option)}
              >
                {option.label}
              </li>
            ))}
          </ul>
          {filtered.length === 0 && (
            <p role="status" className="px-3 py-2 text-sm text-ink-muted">
              Nenhuma variante encontrada.
            </p>
          )}
        </div>
      )}
    </div>
  );
}
