import { forwardRef, type SelectHTMLAttributes } from "react";
import { cn } from "./utils";

export const Select = forwardRef<
  HTMLSelectElement,
  SelectHTMLAttributes<HTMLSelectElement>
>(function Select({ className, ...props }, ref) {
  return (
    <select
      ref={ref}
      className={cn(
        "h-10 w-full rounded-md border border-line-strong bg-surface px-3 text-body text-ink transition-colors placeholder:text-ink-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-foreground aria-[invalid=true]:border-danger-status",
        className,
      )}
      {...props}
    />
  );
});
