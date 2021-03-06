"use strict";
/**
 * formr.org main.js
 * @requires jQuery
 */
webshim.setOptions(
		{
			extendNative: false,
			forms: {
				customDatalist: true,
				addValidators: true,
				waitReady: false,
				replaceValidationUI: true
			},
			geolocation: {
				confirmText: '{location} wants to know your position. You will have to enter one manually if you decline.'
			},
			'forms-ext': {
				types: 'range date time number month color',
				customDatalist: true,
				replaceUI: {range: true}
			}
		});
webshim.polyfill('es5 forms forms-ext geolocation');
webshim.activeLang('de');


$(document).ready(function () {
	if ($(".schmail").length == 1) {
		var schmail = $(".schmail").attr('href');
		schmail = schmail.replace("IMNOTSENDINGSPAMTO", "").
				replace("that-big-googly-eyed-email-provider", "gmail").
				replace(encodeURIComponent("If you are not a robot, I have high hopes that you can figure out how to get my proper email address from the above."), "").
				replace(encodeURIComponent("\r\n\r\n"), "");
		$(".schmail").attr('href', schmail);
	}
	if ($(".navbar-toggle").length == 1)
		new FastClick($(".navbar-toggle")[0]); // particularly annoying if this one doesn't fastclick

	$('*[title]').tooltip({
		container: 'body'
	});
	$('abbr.abbreviated_session').click(function ()
	{
		$(this).text($(this).data("full-session"));
		$(this).off('click');
	});

	hljs.initHighlighting();
	$('.nav-tabs').stickyTabs();
	$('.tab-content').stickyCollapsible();

	// Higlight current menu item
	$('ul.menu-highlight a').each(function(){
		var $a = $(this);
		var href = $a.attr('href');
		if (href == document.location.href) {
			$a.parents('li').addClass('active');
		}
	});
});

function bootstrap_alert(message, bold, where, cls) {
	cls = cls || 'alert-danger';
	var $alert = $('<div class="row"><div class="col-md-6 col-sm-6 all-alerts"><div class="alert '+cls+'"><button type="button" class="close" data-dismiss="alert">&times;</button><strong>' + (bold ? bold : 'Problem') + '</strong> ' + message + '</div></div></div>');
	$alert.prependTo($(where));
	$alert[0].scrollIntoView(false);
}

function bootstrap_modal(header, body) {
	var $modal = $($.parseHTML(getHTMLTemplate('tpl-test-modal', {'body': body, 'header': header})));
	$modal.modal('show').on('hidden.bs.modal', function () {
		$modal.remove();
	});
    return $modal;
}

function bootstrap_spinner() {
	return ' <i class="fa fa-spinner fa-spin"></i>';
}

function ajaxErrorHandling(e, x, settings, exception) {
	var message;
	var statusErrorMap = {
		'400': "Server understood the request but request content was invalid.",
				'401': "You don't have access.",
				'403': "You were logged out while coding, please open a new tab and login again. This way no data will be lost.",
				'404': "Page not found.",
				'500': "Internal Server Error.",
				'503': "Server can't be reached."
			};
	if (e.status) {
		message = statusErrorMap[e.status];
		if (!message)
			message = (typeof e.statusText !== 'undefined' && e.statusText !== 'error') ? e.statusText : 'Unknown error. Check your internet connection.';
	}
	else if (e.statusText === 'parsererror')
		message = "Parsing JSON Request failed.";
	else if (e.statusText === 'timeout')
		message = "The attempt to save timed out. Are you connected to the internet?";
	else if (e.statusText === 'abort')
		message = "The request was aborted by the server.";
	else
		message = (typeof e.statusText !== 'undefined' && e.statusText !== 'error') ? e.statusText : 'Unknown error. Check your internet connection.';

	if (e.responseText) {
		var resp = $(e.responseText);
		resp = resp.find(".alert").addBack().filter(".alert").html();
		message = message + "<br>" + resp;
	}

	bootstrap_alert(message, 'Error.', '.main_body');
}

function stringTemplate(string, params) {
	for (var i in params) {
		var t = "%?\{"+i+"\}";
		string = string.replace((new RegExp(t, 'g')), params[i]);
	}
	return string;
}

function getHTMLTemplate(id, params) {
   var $tpl = jQuery('#'+id);
   if (!$tpl.length) return;
   return stringTemplate($.trim($tpl.html()), params);
}

function toggleElement(id) {
	$('#'+id).toggleClass('hidden');
}

/**
 * jQuery Plugin: Sticky Tabs
 *
 * @author Aidan Lister <aidan@php.net>
 * @version 1.0.0
 */
(function ($) {
	$.fn.stickyTabs = function () {
		var context = this;
		// Show the tab corresponding with the hash in the URL, or the first tab.
		var showTabFromHash = function () {
			var hash = window.location.hash;
			var selector = hash ? 'a[href="' + hash + '"]' : 'li.active > a';
			$(selector, context).tab('show');
		}

		// Set the correct tab when the page loads
		showTabFromHash(context);

		// Set the correct tab when a user uses their back/forward button
		window.addEventListener('hashchange', showTabFromHash, false);

		// Change the URL when tabs are clicked
		$('a', context).on('click', function (e) {
			history.pushState(null, null, this.href);
		});

		return this;
	};
}(jQuery));

/**
 * jQuery Plugin: Sticky Accordion
 *
 */
(function ($) {
	$.fn.stickyCollapsible = function () {
		var context = this;

		// Show the tab corresponding with the hash in the URL, or the first tab.
		var showCollapsibleFromHash = function () {
			var hash = window.location.hash;
			if (hash) {
				var tab = hash;
				/*				$(context).on('shown.bs.collapse', function(e) {
				 var offset = $('.panel.panel-default > .panel-collapse.in', context).offset();
				 if (offset) {
				 console.log(offset);
				 $('html').animate({
				 scrollTop: offset + 66
				 }, 500);
				 }
				 });
				 */
				var parent_tab = $(tab, context).parents('.tab-pane');
				if (parent_tab && !parent_tab.hasClass("active")) {
					$('a[href=#' + parent_tab.attr('id') + ']').tab('show');
				}
				if (!$(tab, context).hasClass('active'))
					$(tab, context).collapse("show");
			}
		};

		// Set the correct tab when the page loads
		showCollapsibleFromHash(context);

		// Set the correct tab when a user uses their back/forward button
		window.addEventListener('hashchange', showCollapsibleFromHash, false);

		// Change the URL when tabs are clicked
		$('a', context).on('click', function (e) {
			history.pushState(null, null, this.href);
		});

		return this;
	};
}(jQuery));
