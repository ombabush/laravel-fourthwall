{{-- The shop's live promotion, as a line on the page — «−15% with SNIFF15».

     Reads Fourthwall's own promotions (needs the Platform API user) and shows
     the first one live, or the one whose code is given. Auto-applying
     promotions need no code and say so. Renders nothing when there is none:
     a promo banner that outlives its promotion is a lie on the page.

     <x-fourthwall::promo />
     <x-fourthwall::promo code="SNIFF15" :href="route('shop')" /> --}}
@props(['code' => null, 'href' => null, 'text' => null])
@php
	$fw = app(\Ombabush\Fourthwall\Fourthwall::class);
	$promo = collect($fw->promotions())->first(fn ($p) => $code === null || strcasecmp((string) $p['code'], $code) === 0);
	if ($promo) {
		$d = $promo['discount'];
		$what = match (true) {
			isset($d['percentage']) => '−'.rtrim(rtrim(number_format($d['percentage'], 2, '.', ''), '0'), '.').'%',
			isset($d['amount']) => '−'.\Ombabush\Fourthwall\Data\Money::fromArray($d['amount'])->format(),
			! empty($d['free_shipping']) => __('fourthwall::shop.free_shipping'),
			default => $promo['title'] ?? '',
		};
		$href ??= $fw->shopUrl().($promo['code'] && ! $promo['automatic'] ? '/cart/checkout?'.http_build_query(['coupon' => $promo['code']] + $fw->linkParams()) : '');
	}
@endphp
@if ($promo)
	<a {{ $attributes->class('fw-promo') }} href="{{ $href }}" @unless (str_starts_with($href, url('/'))) target="_blank" rel="noopener" @endunless>
		<span class="fw-promo__what">{{ $what }}</span>
		<span class="fw-promo__text">
			{{ $text ?? ($promo['automatic'] ? __('fourthwall::shop.promo_automatic') : __('fourthwall::shop.promo_code')) }}
			@if ($promo['code'] && ! $promo['automatic'])<code class="fw-promo__code">{{ $promo['code'] }}</code>@endif
		</span>
	</a>
@endif
