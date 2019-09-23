function initMap() {
  var mapId = 'map';
  var mapConfig = {
    zoom: 2,
    center: {lat: 59.325, lng: 18.070}
  };
  var minPins = 30;
  var mapElement = document.getElementById(mapId);
  var map = new google.maps.Map(mapElement, mapConfig);
  var infoTemplate = '<article><h2 class="m-type-size-S">Reader from $pin.location</h2><p><div class="title"><a href="$pin.url" target="_blank">$pin.title</a></div><div class="author">$pin.author</div></p></article>';
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
    if (pins.length > 180) { return; }
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
    if (roll < 0.70) {
      return 1;
    }
    else if (roll < 0.90) {
      return 2;
    }
    else {
      return 3;
    }
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
  var pinFetcher = setInterval(fetchPins, 60000);
  var pinDropper = setInterval(dropPins, 1000);
  var pinPuller = null;
  setTimeout(function() {
    pinPuller  = setInterval(pullPins, 1000);
  }, 5000);
}
