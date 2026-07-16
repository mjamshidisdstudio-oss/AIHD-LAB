// Mirrors the JSON shapes returned by the Laravel API resources
// (App\Http\Resources\Marketplace\*, App\Http\Resources\Order/Request/Result).

export type ServiceKind = 'internal' | 'external'
export type ServiceInputType = 'text' | 'image' | 'video' | 'select' | 'boolean' | 'bundle' | 'conditional_group'
export type ServiceOutputType = 'text' | 'image' | 'video'
export type EntryMode = 'wizard' | 'chat'
export type OrderStatus = 'processing' | 'completed' | 'failed'
export type RequestStatus = 'queued' | 'sent' | 'awaiting' | 'polling' | 'completed' | 'failed'
export type ResultType = 'text' | 'image' | 'video'

export interface ServiceCard {
  id: string
  slug: string
  name: string
  tagline: string | null
  image_url: string | null
  kind: ServiceKind
  external_url: string | null
  category: string
  vote_up: number
  vote_down: number
  avg_latency_ms: number | null
  trending_rank: number | null
  coin_cost: number | null
  is_free: boolean
  published_at: string | null
  is_bookmarked: boolean
  my_vote: 1 | -1 | null
  created_at: string
}

export interface ServiceInputOption {
  id: string
  input_id: string
  slug: string
  label: string
  color: string | null
  icon: string | null
  sort_order: number
  parent_option_ids: string[]
}

export interface ServiceInput {
  id: string
  service_version_id: string
  slug: string
  title: string
  type: ServiceInputType
  required: boolean
  multi_select: boolean
  searchable: boolean
  depends_on_input_id: string | null
  depends_on_value: string | null
  sort_order: number
  config: Record<string, unknown> | null
  options: ServiceInputOption[]
}

export interface ServiceOutput {
  id: string
  service_version_id: string
  result_number: number
  type: ServiceOutputType
}

export interface ServiceWaitingText {
  id: string
  service_version_id: string
  text: string
  sort_order: number
}

export interface ServiceVersion {
  id: string
  coin_cost: number
  regenerate_limit: number
  published_at: string | null
  inputs: ServiceInput[]
  outputs: ServiceOutput[]
  waiting_texts: ServiceWaitingText[]
}

export interface ServiceComment {
  id: string
  user_ref: string
  body: string
  sentiment: 'positive' | 'neutral' | 'negative'
  created_at: string
  replies: ServiceComment[]
}

export interface ServiceDetail extends ServiceCard {
  description: string | null
  version: ServiceVersion
  comment_count: number
  comments: ServiceComment[]
  similar: ServiceCard[]
}

export interface ResultFile {
  id: string
  mime: string
  size: number
}

export interface OrderResult {
  id: string
  result_number: number
  type: ResultType
  source: 'webhook' | 'poll'
  text_value: string | null
  latency_ms: number | null
  received_at: string | null
  file?: ResultFile
}

export interface OrderRequest {
  id: string
  attempt_no: number
  status: RequestStatus
  failure_stage: string | null
  get_poll_count: number
  sent_at: string | null
  last_polled_at: string | null
  results: OrderResult[]
}

export interface Order {
  id: string
  user_ref: string
  service_id: string
  service_version_id: string
  status: OrderStatus
  source: string
  entry_mode: EntryMode
  coins_charged: number
  regenerated_from_order_id: string | null
  root_order_id: string | null
  completed_at: string | null
  created_at: string
  requests: OrderRequest[]
}
