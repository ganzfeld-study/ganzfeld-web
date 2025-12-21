  function Zufallswert(MaxNum=1, floating=false) {
  var xhttp = new XMLHttpRequest();
  xhttp.open("POST", "https://qpsy.de/get_random_bit.php", false);
    let formData = new FormData();
  formData.append("MaxNum", MaxNum);
  if (floating) formData.append("Floating", 1);
  xhttp.send(formData);
    if ((!(xhttp.readyState == 4)) || (!(xhttp.status == 200)))  
      {alert("Fehler bei XMLHttpRequest:" +xhttp.readyState +","+xhttp.status); return null;};
  
  if (xhttp.response.split(" ")[0] == "quantis")
    return xhttp.response.split(" ")[1];
    else
    {
    alert("Fehler bei Quantis-php-Aufruf:      "+xhttp.response);
     return null;
    };
  }
