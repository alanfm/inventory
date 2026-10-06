import { cva, type VariantProps } from "class-variance-authority";
import type { HTMLAttributes } from "react";
import { cn } from "./utils";

const badgeVariants = cva(
  "inline-flex items-center gap-1 rounded-pill border px-2.5 py-0.5 text-caption",
  {
    variants: {
      variant: {
        success: "border-success bg-success-surface text-success",
        warning: "border-warning bg-warning-surface text-warning",
        danger:
          "border-danger-status bg-danger-status-surface text-danger-status",
        info: "border-info bg-info-surface text-info",
        neutral: "border-neutral bg-neutral-surface text-neutral",
      },
    },
    defaultVariants: {
      variant: "neutral",
    },
  },
);

export interface BadgeProps
  extends HTMLAttributes<HTMLSpanElement>, VariantProps<typeof badgeVariants> {}

export function Badge({ className, variant, ...props }: BadgeProps) {
  return (
    <span className={cn(badgeVariants({ variant }), className)} {...props} />
  );
}
