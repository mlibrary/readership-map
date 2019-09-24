function initMap() {
  var mapConfig = {"zoom":2,"center":{"lat":59.325,"lng":18.07}};
  var minPins = 30;
  var infoTemplate = "<article>\n  <h2 class=\"m-type-size-S\">Reader from $pin.location<\/h2>\n  <p>\n    <div class=\"title\"><a href=\"$pin.url\" target=\"_blank\">$pin.title<\/a><\/div>\n    <div class=\"author\">$pin.author<\/div>\n  <\/p>\n<\/article>";
  var fetchPinInterval = 60000;
  var fetchPinLevel = 180;
  var dropPinInterval = 1000;
  var pullPinInterval = 1000;
  var pinDistribution = [{"weight":0.7,"count":1},{"weight":0.2,"count":2},{"weight":0.1,"count":3}];

  var mapId = 'map';
  var mapElement = document.getElementById(mapId);
  var map = new google.maps.Map(mapElement, mapConfig);
  var lastInfoWindow = null;

  function dataFunction(data) {
    $('#total-content-requests').text(numeral(data.pageviews.total).format('0,0'));
    $('#annual-content-requests').text(numeral(data.pageviews.annual).format('0,0'));
    $.each(data.pins, function(index, element) {
      pins.push({
        title: element.title,
        url: element.url,
        author: element.author,
        location: element.location,
        position: element.position,
        map: map,
        draggable: true,
        animation: google.maps.Animation.DROP
      });
    });
  }

  function fetchPins() {
    if (pins.length > fetchPinLevel) { return; }
    $.getJSON('pins.json?cb=' + Date.now(), dataFunction);
  }

  function infoContent(pin) {
    return infoTemplate
      .replace('$pin.url', pin.url)
      .replace('$pin.title', pin.title)
      .replace('$pin.author', pin.author)
      .replace('$pin.location', pin.location)
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
    var total = numeral($('#total-content-requests').text()).value();
    var annual = numeral($('#annual-content-requests').text()).value();

    $('#total-content-requests').text(numeral(total + dropCount).format('0,0'));
    $('#annual-content-requests').text(numeral(annual + dropCount).format('0,0'));
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
