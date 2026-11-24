import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-base font-semibold transition-colors disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground hover:bg-primary/90",
        // Signal red. One per view: the action that moves the week forward.
        signal:
          "bg-signal text-signal-foreground hover:bg-signal-hover",
        destructive:
          "bg-destructive-fill text-white hover:bg-destructive-fill/90",
        outline:
          "border border-input bg-card hover:bg-accent hover:text-accent-foreground",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-accent",
        ghost:
          "hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        // 46px is the control height of the guide, 44px its smallest target.
        "default": "min-h-[46px] px-5.5 py-2 has-[>svg]:px-4",
        "sm": "min-h-11 px-4 py-1.5 has-[>svg]:px-3",
        "lg": "min-h-[50px] rounded-lg px-6 py-2 text-body has-[>svg]:px-5",
        "icon": "size-[46px]",
        "icon-sm": "size-11",
        "icon-lg": "size-[50px]",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
