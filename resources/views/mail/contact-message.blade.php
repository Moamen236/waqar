New message from the storefront contact form.

From: {{ $senderName }} <{{ $senderEmail }}>
@if ($orderNumber)
Order: #{{ $orderNumber }}
@endif

----------------------------------------
{{ $body }}
----------------------------------------

Reply directly to this email to answer the customer.
