@props(['amount'])

<span {{ $attributes->class('whitespace-nowrap tabular-nums') }}>{{ number_format((int) $amount, 0, ',', "\u{00A0}") }}&nbsp;F&nbsp;CFA</span>
