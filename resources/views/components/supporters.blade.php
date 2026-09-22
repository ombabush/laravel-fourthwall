{{-- A thank-you wall: who gave, how much, and what they said.

     Only what the donor typed into the public form — a name and a message.
     Fourthwall's donation record also carries their e-mail address; it is
     dropped before anything is cached and never reaches a page.
     Needs the Platform API user. Renders nothing without donations.

     <x-fourthwall::supporters :limit="12" />
     <x-fourthwall::supporters :amounts="false" />   names and messages only --}}
@props(['limit' => 12, 'amounts' => true, 'heading' => null])
@php($rows = app(\Ombabush\Fourthwall\Fourthwall::class)->supporters((int) $limit))
@if ($rows)
	<section {{ $attributes->class('fw-supporters') }}>
		@if ($heading)<h2 class="fw-supporters__heading">{{ $heading }}</h2>@endif
		<ul class="fw-supporters__list">
			@foreach ($rows as $r)
				<li class="fw-supporter">
					<span class="fw-supporter__name">{{ $r['name'] ?? __('fourthwall::shop.anonymous') }}</span>
					@if ($amounts && $r['amount'])<span class="fw-supporter__amount">{{ $r['amount']->format() }}</span>@endif
					@if ($r['message'])<q class="fw-supporter__message">{{ $r['message'] }}</q>@endif
				</li>
			@endforeach
		</ul>
	</section>
@endif
