(function (next) {
      try {
        var o = window.opener;
        if (o && ! o.closed && o.location.host === location.host) {
          o.location.href = next;
          if (next.split('#')[0] === o.location.href.split('#')[0]) o.location.reload();
          window.close();
          return;
        }
      } catch (e) {}
      location.replace(next);
    })(window.aiworkerChatPopup.next);
