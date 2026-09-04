import type { VariantProps } from 'class-variance-authority'
import { cva } from 'class-variance-authority'

export { default as InputOTP } from './InputOTP.vue'
export { default as InputOTPGroup } from './InputOTPGroup.vue'
export { default as InputOTPSeparator } from './InputOTPSeparator.vue'
export { default as InputOTPSlot } from './InputOTPSlot.vue'

export const inputOTPSlotVariants = cva(
  'dark:bg-input/30 border-input data-[active=true]:border-ring data-[active=true]:ring-ring/50 data-[active=true]:aria-invalid:ring-destructive/20 dark:data-[active=true]:aria-invalid:ring-destructive/40 aria-invalid:border-destructive data-[active=true]:aria-invalid:border-destructive border-y border-r transition-all outline-none first:rounded-l-lg first:border-l last:rounded-r-lg data-[active=true]:ring-3 relative flex items-center justify-center data-[active=true]:z-10',
  {
    variants: {
      size: {
        default: 'size-8 text-sm',
        lg: 'size-12 text-lg',
        xl: 'size-14 text-2xl font-semibold',
      },
    },
    defaultVariants: {
      size: 'default',
    },
  },
)
export type InputOTPSlotVariants = VariantProps<typeof inputOTPSlotVariants>
