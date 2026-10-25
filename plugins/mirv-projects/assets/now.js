(function(){
  function refresh(){
    var target=document.getElementById('mirvp-now-cards');
    if(!target || !window.MIRVP_NOW_URL || typeof fetch!=='function') return;
    fetch(window.MIRVP_NOW_URL,{cache:'no-store',credentials:'omit'})
      .then(function(response){if(!response.ok)throw new Error('status');return response.json();})
      .then(function(data){if(data && typeof data.html==='string' && data.html.trim())target.innerHTML=data.html;})
      .catch(function(){/* Keep server-rendered cards when offline. */});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',refresh);
  else refresh();
})();