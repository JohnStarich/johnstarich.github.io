$(function() {
	var refreshing = false;
	var refresherIntervalId;
	var links = $("link");

	var refreshPane = $('<div id="refresher">Refresher: ' + (refreshing ? 'Running' : 'Paused') + '</div>')
		.css({
			position: 'fixed',
			top: 0,
			right: 0,
			padding: 10,
			margin: 10,
			color: '#000',
			'background-color': '#DDD',
			'font-family': 'sans-serif',
			'font-size': '1em',
			'font-weight': 'normal',
			'z-index': 9999
		})
		.appendTo('body');

	function startRefresher() {
		refreshPane.html('Refresher:<br>Running');
		refresherIntervalId = window.setInterval(function() {
			if(refreshing) {
				links.each(function() {
					var $this = $(this);
					if ($this.attr("type").indexOf("css") > -1) {
						var newhref = $(this).attr("href");
						if(newhref.indexOf('?') >= 0)
							newhref = newhref.substring(0, newhref.indexOf('?'));
						newhref += "?id=" + new Date().getMilliseconds();
						$this.attr("href", newhref);
					}
				});
			}
		}, 3000);
	}

	function stopRefresher() {
		refreshPane.html('Refresher:<br>Paused');
		window.clearInterval(refresherIntervalId);
	}

	if(refreshing)
		startRefresher();
	else
		stopRefresher();

	refreshPane.on('click', function() {
		refreshing = !refreshing;
		if(refreshing)
			startRefresher();
		else
			stopRefresher();
	});
});
