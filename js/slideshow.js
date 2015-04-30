$.fn.extend({
	/**
	 * Slideshow: Turns the current object into a slideshow element.
	 * Classes used: slidein, slide-left, slide-right, slideout-left, slideout-right
	 * If no buttons are provided, some will be prepended to this container.
	 * @param slideSelector the selector to identify slideshow elements
	 * @param leftButtonSelector selector to identify the left slideshow button, default value is #left_button
	 * @param rightButtonSelector selector to identify the right slideshow button, default value is #right_button
	 */
	slideshow: function(slideSelector, leftButtonSelector, rightButtonSelector) {
		var $this = $(this);

		if(! leftButtonSelector)
			leftButtonSelector = '#left_button';
		if(! rightButtonSelector)
			rightButtonSelector = '#right_button';
		var leftButton = $this.find(leftButtonSelector);
		var rightButton = $this.find(rightButtonSelector);
		if(leftButton.length == 0 || rightButton.length == 0) {
			leftButton = $('<button id="left_button">&lt;</button>').appendTo($this);
			rightButton = $('<button id="right_button">&gt;</button>').appendTo($this);
		}
		var buttons = leftButton.add(rightButton);
		
		var slides = $this.find(slideSelector);
		if(slides.length == 0)
			slides = $($this.children().not(buttons));
		
		var slidingOut = slides.find('.slidein');
		if(slidingOut.length == 0) {
			slidingOut = slides.first();
			slidingOut.addClass('slidein');
		}
		var slidingIn;

		function nextSlide(slideToTheRight) {
			buttons.prop('disabled', true);
			if(slideToTheRight)
				slidingIn = slides.eq(slides.index(slidingOut) + 1 - slides.length);
			else
				slidingIn = slides.eq(slides.index(slidingOut) - 1);
			
			slidingOut
				.removeClass('slidein')
				.addClass(function() {
					if(slideToTheRight)
						return 'slideout-left';
					else
						return 'slideout-right';
				})
				.one('transitionend', function() {
					$(this).removeClass('slideout-left slideout-right')
				});

			slidingOut = slidingIn
				.addClass(function() {
					if(slideToTheRight)
						return 'slide-right';
					else
						return 'slide-left';
				})
				.show()
				.addClass('slidein')
				.removeClass('slide-left slide-right')
				.one('transitionend', function() {
					buttons.prop('disabled', false);
				});
		}

		leftButton.on('click', function() {
			nextSlide(false);
		});

		rightButton.on('click', function() {
			nextSlide(true);
		});

		$this.touchwipe({
			wipeLeft: function() { nextSlide(true); },
			wipeRight: function() { nextSlide(false); },
			wipeUp: false,
			wipeDown: false,
			min_move_x: 60,
			min_move_y: 75,
			preventDefaultEvents: false
		});

		return this;
	}
});