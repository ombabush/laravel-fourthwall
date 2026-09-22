{{-- A donation form that hands over to Fourthwall's own donation page — the
     same GET its shop sends, pre-filled. We take no money and see no card.

     <x-fourthwall::donate :amounts="[5, 10, 20]" />

     The amount is a radio group; «other» reveals a number field. Without
     JavaScript the chosen radio is sent and the amount can still be changed
     on Fourthwall's page. --}}
@props(['amounts' => [5, 10, 20], 'default' => null, 'currency' => null, 'params' => []])
@php
	$fw = app(\Ombabush\Fourthwall\Fourthwall::class);
	$currency = strtoupper($currency ?? config('fourthwall.currency', 'USD'));
	$money = fn ($v) => \Ombabush\Fourthwall\Data\Money::fromDecimal((string) $v, $currency);
	$default ??= $amounts[intdiv(count($amounts), 2)] ?? null;
	$uid = 'fw-donate-'.substr(md5(uniqid('', true)), 0, 6);
@endphp
@if ($fw->shopUrl())
	<form {{ $attributes->class('fw-donate') }} id="{{ $uid }}" method="get" action="{{ $fw->shopUrl() }}/donation/" target="_blank">
		<fieldset class="fw-donate__amounts">
			<legend>{{ __('fourthwall::shop.amount') }}</legend>
			@foreach ($amounts as $a)
				<label class="fw-donate__amount">
					<input type="radio" name="amount" value="{{ $money($a)->decimal() }}" @checked((string) $a === (string) $default)>
					<span>{{ $money($a)->format() }}</span>
				</label>
				<input type="hidden" name="donationOpts[]" value="{{ $money($a)->decimal() }}">
			@endforeach
			<label class="fw-donate__amount fw-donate__amount--other">
				<input type="radio" name="amount" value="" data-fw-other>
				<span>{{ __('fourthwall::shop.other') }}</span>
			</label>
			<input class="fw-donate__custom" type="number" min="1" step="0.01" inputmode="decimal"
			       placeholder="{{ $money(25)->format() }}" data-fw-custom hidden>
		</fieldset>
		<label class="fw-donate__field">
			<span>{{ __('fourthwall::shop.your_name') }}</span>
			<input type="text" name="donor" autocomplete="name" maxlength="100">
		</label>
		<label class="fw-donate__field">
			<span>{{ __('fourthwall::shop.message') }}</span>
			<textarea name="message" rows="3" maxlength="200"></textarea>
		</label>
		<input type="hidden" name="currency" value="{{ $currency }}">
		@foreach ($fw->linkParams($params) as $k => $v)
			<input type="hidden" name="{{ $k }}" value="{{ $v }}">
		@endforeach
		<button class="fw-buy" type="submit">{{ __('fourthwall::shop.donate') }}</button>
	</form>
	<script>
	(function (f) {
		var other = f.querySelector('[data-fw-other]'), custom = f.querySelector('[data-fw-custom]');
		f.addEventListener('change', function () { custom.hidden = !other.checked; if (other.checked) custom.focus(); });
		custom.addEventListener('input', function () { other.value = custom.value; });
	})(document.getElementById(@json($uid)));
	</script>
@endif
