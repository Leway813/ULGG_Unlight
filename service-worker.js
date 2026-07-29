self.addEventListener('push', function(event) {
  let data = {};

  if (event.data) {
    try {
      data = event.data.json();
    } catch (e) {
      data = {
        title: 'ULGG 杯賽事消息',
        body: event.data.text()
      };
    }
  }

  const title = data.title || 'ULGG 杯賽事消息';

  const options = {
    body: data.body || '有新的賽事消息。',
    icon: data.icon || '/assets/favicon/android-chrome-192x192.png',
    badge: data.badge || '/assets/favicon/android-chrome-192x192.png',
    data: {
      url: data.url || '/pages/tournament/ulgg_cup.php'
    }
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

self.addEventListener('notificationclick', function(event) {
  event.notification.close();

  const url = event.notification.data && event.notification.data.url
    ? event.notification.data.url
    : '/pages/tournament/ulgg_cup.php';

  event.waitUntil(
    clients.matchAll({
      type: 'window',
      includeUncontrolled: true
    }).then(function(clientList) {
      for (const client of clientList) {
        if (client.url === url && 'focus' in client) {
          return client.focus();
        }
      }

      if (clients.openWindow) {
        return clients.openWindow(url);
      }
    })
  );
});
