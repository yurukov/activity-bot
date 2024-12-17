const timeParse = d3.timeParse("%Y-%m-%d %H:%M:%S");

if (updateTimes()) setInterval(updateTimes,60000);

function updateTimes() {
  let hasCurrentPosts=false;

  d3.selectAll("article div:last-child a").nodes().forEach(n=>{
    let ndate;
    if (n.hasAttribute("time"))
      ndate=n.getAttribute("time");
    else {
      ndate=n.innerHTML;
      n.setAttribute("time",ndate);
    }
    ndate=timeParse(ndate);
    if (!ndate) return;
    let age = new Date().getTime()-ndate;

    if (age<1000*60*90) hasCurrentPosts=true;

    if (age<1000*10) age="just now";
    else if (age<1000*60) age="less than a minute";
    else if (age<1000*60*90) age=Math.round(age/1000/60)+" minutes ago";
    else if (age<1000*60*60*12) age=Math.round(age/1000/60/60)+" hours ago";
    else age=null;

    if (age) n.innerHTML=age;
  });

  return hasCurrentPosts; 
}

let button = d3.select(".button-mastodon");
if (button.size()>0)
  button.node().onclick = ()=>{
    navigator.clipboard.writeText(d3.select(".button-mastodon").attr("href"))
      .then(() => showInfo("The address of the account is copied to your clipboard. Search for it in your Mastodon client or site."),
            () => showInfo("Copy the address of the account and search for it in your Mastodon client or site."));
    return false;
  };

function showInfo(text) {
  let span = d3.select("#infobox span");
  span.text(text);
  if (d3.select("#infobox").style("height") == "0px") {
    d3.select("#infobox").transition().duration(800)
      .on("end",()=>d3.select("#infobox").style("height","unset"))
      .style("height",(span.node().clientHeight+22)+"px");
  }
}
