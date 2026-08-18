@if($filters['batch'])<input type="hidden" name="return_batch" value="{{ $filters['batch'] }}">@endif
@if($filters['reason'])<input type="hidden" name="return_reason" value="{{ $filters['reason'] }}">@endif
<input type="hidden" name="return_state" value="{{ $filters['state'] ?? 'all' }}">
@if($filters['q'] !== '')<input type="hidden" name="return_q" value="{{ $filters['q'] }}">@endif
@if($filters['item'])<input type="hidden" name="return_item" value="{{ $filters['item'] }}">@endif
@if(request()->integer('companies_page') > 1)<input type="hidden" name="return_companies_page" value="{{ request()->integer('companies_page') }}">@endif
