// Mirrors the JSON shapes returned by the Laravel API resources
// (App\Http\Resources\ServiceResource, App\Http\Resources\ServiceVersionResource,
// App\Http\Resources\Admin\*).

export type ServiceKind = 'internal' | 'external'
export type ServiceStatus = 'active' | 'paused' | 'auto_disabled'
export type ServiceVersionStatus = 'draft' | 'published' | 'retired'
export type ServiceInputType = 'text' | 'image' | 'video' | 'select' | 'boolean' | 'bundle' | 'conditional_group'
export type ServiceOutputType = 'text' | 'image' | 'video'
export type EntryMode = 'wizard' | 'chat'
export type OrderSource = 'site' | 'admin_preview'
export type OrderStatus = 'processing' | 'completed' | 'failed'
export type RequestStatus = 'queued' | 'sent' | 'awaiting' | 'polling' | 'completed' | 'failed'
export type FailureStage = 'post' | 'timeout' | 'service'
export type ResultType = 'text' | 'image' | 'video'
export type ResultSource = 'webhook' | 'poll'
export type WebhookOutcome = 'ingested' | 'duplicate' | 'invalid_signature' | 'unknown_order' | 'stale_attempt' | 'validation_error'
export type CommentSentiment = 'positive' | 'neutral' | 'negative'
export type CommentStatus = 'published' | 'hidden'

export interface Service {
  id: string
  slug: string
  name: string
  description: string | null
  image_url: string | null
  kind: ServiceKind
  external_url: string | null
  category: string
  status: ServiceStatus
  has_secret: boolean
  secret_preview: string | null
  has_webhook_signing_key: boolean
  webhook_signing_key_preview: string | null
  consecutive_failures: number
  current_version_id: string | null
  vote_up: number
  vote_down: number
  avg_latency_ms: number | null
  trending_rank: number | null
  current_version?: ServiceVersion
  versions?: ServiceVersion[]
  created_at: string
  updated_at: string
}

export interface ServiceVersion {
  id: string
  service_id: string
  version_no: number
  status: ServiceVersionStatus
  coin_cost: number
  regenerate_limit: number
  response_timeout_s: number
  get_interval_s: number
  max_get_attempts: number
  post_url: string | null
  get_url: string | null
  published_at: string | null
  inputs?: ServiceInput[]
  outputs?: ServiceOutput[]
  waiting_texts?: ServiceWaitingText[]
  created_at: string
  updated_at: string
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
  options?: ServiceInputOption[]
}

export interface ServiceInputOption {
  id: string
  input_id: string
  slug: string
  label: string
  color: string | null
  icon: string | null
  sort_order: number
  parent_option_ids?: string[]
}

export interface OptionDependency {
  id: string
  option_id: string
  parent_option_id: string
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

export interface AdminOrderListItem {
  id: string
  user_ref: string
  status: OrderStatus
  source: OrderSource
  entry_mode: EntryMode
  coins_charged: number
  regenerated_from_order_id: string | null
  root_order_id: string | null
  created_at: string
  completed_at: string | null
}

export interface AdminWebhookDelivery {
  id: string
  service_id: string
  request_id: string | null
  external_order_id: string | null
  result_number: number | null
  outcome: WebhookOutcome
  http_status: number
  raw_body: string
  received_at: string
}

export interface AdminResult {
  id: string
  result_number: number
  type: ResultType
  source: ResultSource
  latency_ms: number | null
  received_at: string
  file_id: string | null
  text_value: string | null
  download_count: number
}

export interface AdminRequest {
  id: string
  attempt_no: number
  external_order_id: string | null
  status: RequestStatus
  failure_stage: FailureStage | null
  sent_at: string | null
  last_polled_at: string | null
  get_poll_count: number
  results: AdminResult[]
  webhook_deliveries: AdminWebhookDelivery[]
}

export interface AdminOrderInput {
  id: string
  input_id: string
  input_title: string | null
  input_slug: string | null
  input_type: ServiceInputType | null
  value_text: string | null
  value_bool: boolean | null
  selected_options: Array<{ id: string; label: string; slug: string }>
  files: Array<{ id: string; mime: string; size: number }>
}

export interface AdminOutputView {
  result_id: string | null
  result_number: number
  type: ServiceOutputType
  has_result: boolean
  source: ResultSource | null
  latency_ms: number | null
  received_at: string | null
  file_id: string | null
  text_value: string | null
  failure_stage: FailureStage | null
  download_count: number
}

export interface AdminOrder {
  id: string
  service_id: string
  service_version_id: string
  version_no: number | null
  user_ref: string
  status: OrderStatus
  source: OrderSource
  entry_mode: EntryMode
  coins_charged: number
  coin_txn_ref: string | null
  regenerated_from_order_id: string | null
  root_order_id: string | null
  completed_at: string | null
  created_at: string
  requests: AdminRequest[]
  inputs: AdminOrderInput[]
  outputs: AdminOutputView[]
}

export interface AdminComment {
  id: string
  service_version_id: string
  user_ref: string
  body: string
  sentiment: CommentSentiment
  status: CommentStatus
  parent_id: string | null
  created_at: string
  replies: AdminComment[]
}

export interface Paginated<T> {
  data: T[]
  meta_stats?: { total: number; completed: number; failed: number }
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}
