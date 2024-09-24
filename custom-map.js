let map;
let marker = null;

async function initMap(lat, lng) {
  const { Map } = await google.maps.importLibrary("maps");

  const options = {
    zoom: 16,
    center: { lat: lat, lng: lng },
    streetViewControl: false, // Disable Pegman for Street View
  };

  map = new Map(document.getElementById("map"), options);

  marker = new google.maps.Marker({
    position: options.center,
    map: map,
    draggable: true,
  });

  google.maps.event.addListener(marker, "dragend", function () {
    const position = marker.getPosition();
    document.getElementById("custom_latitude").value = position.lat();
    document.getElementById("custom_longitude").value = position.lng();
  });

  // Set initial latitude and longitude input values
  document.getElementById("custom_latitude").value = lat;
  document.getElementById("custom_longitude").value = lng;
}

function detectUserLocation() {
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      function (position) {
        const userLat = position.coords.latitude;
        const userLng = position.coords.longitude;

        // Initialize map with user's location
        initMap(userLat, userLng);
      },
      function (error) {
        console.error("Error detecting location: ", error);
        // Fallback to default location if user denies permission or error occurs
        initMap(3.0966396, 101.6767438);
      }
    );
  } else {
    // Fallback to default location if geolocation is not supported
    initMap(3.0966396, 101.6767438);
  }
}

document.addEventListener("DOMContentLoaded", function () {
  detectUserLocation();

  document
    .getElementById("get_location_button")
    .addEventListener("click", function () {
      // Safely retrieve elements
      const addressField = document.querySelector(
        'input[name="custom_address"]'
      );
      const latitudeField = document.getElementById("custom_latitude");
      const longitudeField = document.getElementById("custom_longitude");
      const phoneField = document.querySelector('input[name="billing_phone"]');
      const firstNameField = document.querySelector(
        'input[name="billing_first_name"]'
      );
      const lastNameField = document.querySelector(
        'input[name="billing_last_name"]'
      );
      const remarksField = document.querySelector(
        'textarea[name="order_comments"]'
      );

      // Check if elements exist before accessing their values
      if (
        !addressField ||
        !latitudeField ||
        !longitudeField ||
        !phoneField ||
        !firstNameField ||
        !lastNameField ||
        !remarksField
      ) {
        console.error("One or more required fields are missing in the DOM.");
        return;
      }

      const address = addressField.value;
      const latitude = latitudeField.value;
      const longitude = longitudeField.value;
      const phone = phoneField.value;
      const firstName = firstNameField.value;
      const lastName = lastNameField.value;
      const remarks = remarksField.value;

      if (!address) {
        alert("Please enter your custom address.");
        return;
      }

      if (!latitude || !longitude) {
        alert("Please pin your location on the map.");
        return;
      }

      const data = {
        action: "save_location_data",
        address: address,
        latitude: latitude,
        longitude: longitude,
        phone: phone,
        firstName: firstName,
        lastName: lastName,
        remarks: remarks,
        order_id: document.getElementById("order_id")
          ? document.getElementById("order_id").value
          : "", // Add a check for order_id
      };

      jQuery.post(wc_map_params.ajax_url, data, function (response) {
        if (response.success) {
          document.getElementById("location_status").innerText = "";
          jQuery("body").trigger("update_checkout");
          alert(
            "The Lalamove price has been received and updated in your order summary. Please note that this price is only valid for the next 5 minutes."
          );
        } else {
          document.getElementById("location_status").innerText = "";
        }
      });
    });

  // Load the Google Maps API script with the API key
  const script = document.createElement("script");
  script.src = `https://maps.googleapis.com/maps/api/js?key=${wc_map_params.google_maps_api_key}&callback=detectUserLocation&libraries=places&v=weekly`;
  script.async = true;
  document.head.appendChild(script);
});
