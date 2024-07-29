function initMap() {
  var mapConfig = {"zoom":2,"center":{"lat":0,"lng":0}};
  var minPins = 30;
  var infoTemplate = "<article>\n  <h2 class=\"m-type-size-S\">Reader from $pin.location<\/h2>\n  <p>\n    <img class=\"access $pin.access\" src=\"assets\/images\/$pin.access.svg\" alt=\"$pin.access access\">\n    <span class=\"metadata\">\n      <span class=\"title\"><a href=\"$pin.url\" target=\"_blank\">$pin.title<\/a><\/span>\n      <span class=\"author\">$pin.author<\/span>\n    <\/span>\n  <\/p>\n<\/article>";
  var fetchPinInterval = 60000;
  var fetchPinLevel = 180;
  var dropPinInterval = 1000;
  var pullPinInterval = 1000;
  var pinDistribution = [{"weight":0.7,"count":1},{"weight":0.2,"count":2},{"weight":0.1,"count":3}];

  var mapId = 'map';
  var mapElement = document.getElementById(mapId);
  var map = new google.maps.Map(mapElement, mapConfig);
  var lastInfoWindow = null;

  var urlParams = parseKEV(document.location.href);

  function parseKEV(str) {
    var params = {};
    if (!str) { return params; }
    var urlParts = str.split('?', 2);
    if (!urlParts || urlParts.length < 2 || ! urlParts[1]) { return params; }
    var kev = urlParts[1].split('&');
    if (!kev || kev.length == 0) { return params; }

    $.each(kev, function(index, element) {
      var kv = element.split('=', 2);
      params[kv[0]] = params[kv[0]] || [];
      params[kv[0]].push(kv[1]);
    });
    return params;
  }

  function filterPins(pins) {
    if (!urlParams['filter.property'] || urlParams['filter.property'].length <= 0) {
      return pins;
    }
    return $.grep(pins, function (element) {
      return $.inArray(element.property_id, urlParams['filter.property']) != -1;
    });
  }

  function sum(list) {
    return list
      .map(function(item) { return item['count']; })
      .reduce(function (a,b) { return a + b; }, 0);
  }

  function getTextNumeral(location) {
    return numeral($(location).text()).value();
  }

  function setTextNumeral(location, val) {
    return $(location).text(numeral(val).format('0,0'));
  }

  function dataFunction(data) {
    var filtered = filterPins(data.pins);
    var pageviews = {
      "total": sum(filterPins(data.pageviews.total)) - filtered.length,
      "annual": sum(filterPins(data.pageviews.annual)) - filtered.length
    };

    setTextNumeral('#total-content-requests', pageviews.total);
    setTextNumeral('#annual-content-requests', pageviews.annual);

    $.each(filtered, function(index, element) {
      pins.push({
        title: element.title,
        url: element.url,
        author: element.author,
        location: element.location,
        position: element.position,
        map: map,
        draggable: true,
        animation: google.maps.Animation.DROP,
        access: element.access
      });
    });
  }

  function fetchPins() {
    if (pins.length > fetchPinLevel) { return; }
    $.getJSON('pins.json?cb=' + Date.now(), dataFunction);
  }

  function infoContent(pin) {
    return infoTemplate
      .replace(/\$pin.url/g, pin.url)
      .replace(/\$pin.title/g, pin.title)
      .replace(/\$pin.author/g, pin.author)
      .replace(/\$pin.location/g, pin.location)
      .replace(/\$pin.access/g, pin.access)
      ;
  }

  function createMarker(pin) {
    var marker = new google.maps.Marker({
      map: pin.map,
      animation: pin.animation,
      position: pin.position
    });
    var info = new google.maps.InfoWindow({
      content: infoContent(pin)
    });
    marker.addListener('click', (function(info, map, marker) {
      return function() {
        if (lastInfoWindow) {
          lastInfoWindow.close();
        }
        info.open(map, marker);
        lastInfoWindow = info;
      }
    })(info, map, marker));
    return marker;
  }

  function rollDropCount() {
    var roll = Math.random();
    var count = 1;
    var i = 0;
    var limit = pinDistribution.length;
    while (roll > 0 && i < limit) {
      roll -= pinDistribution[i].weight;
      count = pinDistribution[i].count;
      ++i;
    }
    return count;
  }

  function dropPins() {
    if (pins.length == 0) { return; }
    var dropCount = Math.min(pins.length, rollDropCount());
    for (var i=0; i < dropCount; ++i) {
      var pin = pins.shift();
      var marker = createMarker(pin);
      markers.push(marker);
    }

    var total = getTextNumeral('#total-content-requests');
    var annual = getTextNumeral('#annual-content-requests');

    setTextNumeral('#total-content-requests', total + dropCount);
    setTextNumeral('#annual-content-requests', annual + dropCount);
  }

  function pullPins() {
    while (markers.length > minPins) {
      var marker = markers.shift();
      marker.setMap(null);
    }
  }

  function fisherYatesShuffle(list) {
    for (var i = list.length -1; i > 0; --i) {
      var j = Math.floor(Math.random() * (i+1));
      var k = list[j];
      list[j] = list[i];
      list[i] = k;
    }
  }

  var pins = [];
  var markers = [];
  fetchPins();
  var pinFetcher = setInterval(fetchPins, fetchPinInterval);
  var pinDropper = setInterval(dropPins, dropPinInterval);
  var pinPuller = setInterval(pullPins, pullPinInterval);
}
