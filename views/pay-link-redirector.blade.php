<html>
    <body>
        <script>
        	var form = document.createElement("form");
        	form.setAttribute("method", "GET");
        	form.setAttribute("action", "{!! $gatewayUrl !!}");
        	form.setAttribute("target", "_self");
        	document.body.appendChild(form);
			form.submit();
			document.body.removeChild(form);
        </script>
		<h1> در حال انتقال به درگاه پرداخت... </h1>
		<style>
			#redirect-link {
				opacity: 0;
				transition: opacity 1s;
				animation: appearAfter5s 1s forwards;
				animation-delay: 5s;
			}
			@keyframes appearAfter5s {
				from { opacity: 0; }
				to { opacity: 1; }
			}
		</style>
		<a id="redirect-link" href="{!! $gatewayUrl !!}" target="_self">انتقال به درگاه پرداخت</a>
    </body>
</html>
