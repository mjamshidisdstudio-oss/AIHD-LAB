// Category is admin-defined free text (services.category) — there is no
// fixed taxonomy to map labels/colors from, so this derives a stable color
// per string (same palette the design uses for its category pills) instead
// of guessing at categories that may not exist.
const PALETTE = ['#5639E5', '#0090F8', '#7F56D9', '#0D9488', '#16A34A', '#E8590C', '#DB2777']

export function useCategoryColor(category: string): string {
  let hash = 0
  for (let i = 0; i < category.length; i++) {
    hash = (hash << 5) - hash + category.charCodeAt(i)
    hash |= 0
  }

  return PALETTE[Math.abs(hash) % PALETTE.length]
}

export function categoryLabel(category: string): string {
  return category.charAt(0).toUpperCase() + category.slice(1)
}
