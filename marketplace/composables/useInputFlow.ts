import type { ServiceInput, ServiceInputOption } from '~/types/api'

export type Answers = Record<string, unknown>

/**
 * The dependency graph that decides which inputs/options are currently
 * visible, given the answers so far:
 *  - an input with depends_on_input_id only shows once its parent input has
 *    an answer; if depends_on_value is also set, the parent's answer must
 *    equal it exactly (see SeasonalViewsSeeder's `style` input for the
 *    "any answer" case with depends_on_value left null).
 *  - a select option gated by option_dependencies only appears once every
 *    one of its parent options is the CURRENTLY SELECTED answer on whatever
 *    input that parent option belongs to (the parent can live on a
 *    different input than the option itself, e.g. style options gated by
 *    room_type options).
 */
export function useVisibleFlow(inputs: ServiceInput[]) {
  const optionOwner = new Map<string, { option: ServiceInputOption; input: ServiceInput }>()
  for (const input of inputs) {
    for (const option of input.options) optionOwner.set(option.id, { option, input })
  }

  function isOptionSelected(optionId: string, answers: Answers): boolean {
    const entry = optionOwner.get(optionId)
    if (!entry) return false
    const answer = answers[entry.input.slug]

    return Array.isArray(answer) ? answer.includes(entry.option.slug) : answer === entry.option.slug
  }

  function isInputVisible(input: ServiceInput, answers: Answers): boolean {
    if (!input.depends_on_input_id) return true
    const parent = inputs.find((i) => i.id === input.depends_on_input_id)
    if (!parent) return true
    const parentAnswer = answers[parent.slug]
    if (input.depends_on_value !== null && input.depends_on_value !== undefined && input.depends_on_value !== '') {
      return String(parentAnswer) === String(input.depends_on_value)
    }

    return parentAnswer !== undefined && parentAnswer !== null && parentAnswer !== ''
  }

  function visibleOptions(input: ServiceInput, answers: Answers): ServiceInputOption[] {
    return [...input.options]
      .filter((opt) => opt.parent_option_ids.length === 0 || opt.parent_option_ids.every((pid) => isOptionSelected(pid, answers)))
      .sort((a, b) => a.sort_order - b.sort_order)
  }

  function visibleInputs(answers: Answers): ServiceInput[] {
    return [...inputs].filter((i) => isInputVisible(i, answers)).sort((a, b) => a.sort_order - b.sort_order)
  }

  function isAnswered(input: ServiceInput, answers: Answers): boolean {
    const value = answers[input.slug]
    if (input.type === 'boolean') return true
    if (input.type === 'image' || input.type === 'video') return value instanceof File
    if (value === undefined || value === null || value === '') return false
    if (Array.isArray(value)) return value.length > 0

    return true
  }

  return { visibleInputs, visibleOptions, isAnswered }
}
