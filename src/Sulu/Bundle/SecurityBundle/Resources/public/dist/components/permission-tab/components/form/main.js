define(["config","sulusecurity/collections/roles"],function(a,b){"use strict";var c="/admin/api/permissions",d=a.get("sulusecurity.permissions"),e=a.get("sulusecurity.permission_titles"),f="#matrix-container",g="#matrix",h={id:null,type:null,securityContext:null,permissions:{}},i=function(){this.sandbox.on("husky.matrix.changed",l.bind(this)),this.sandbox.on("sulu.header.toolbar.save",m.bind(this)),this.sandbox.on("sulu.permission-tab.saved",function(){n.call(this,!0)}.bind(this))},j=function(){this.$el.html(this.renderTemplate("/admin/security/template/permission-tab/form"))},k=function(){var a=this.sandbox.dom.createElement('<div id="matrix" class="loading"/>');this.sandbox.dom.append(f,a);var i=new b;this.sandbox.data.when(i.fetch(),this.sandbox.util.ajax({url:[c,"?type=",h.type,"&id=",h.id].join("")})).done(function(b,c){i=i.toJSON();var f=c[0],j=[],k=[],l=[];this.sandbox.util.each(i,function(a,b){var c={},e=[];if(k.push(b.name),l.push(b.identifier),this.sandbox.util.each(d,function(a,b){c[b.value]=!1}.bind(this)),f.permissions.hasOwnProperty(b.identifier))this.sandbox.util.each(f.permissions[b.identifier],function(a,b){c[a]=b,e.push(b)});else{var g=p.call(this,this.options.securityContext,b);this.sandbox.util.each(g,function(a,b){c[a]=b,e.push(b)})}h.permissions[b.identifier]=c,j[a]=e}.bind(this)),this.sandbox.start([{name:"matrix@husky",options:{el:g,captions:{type:this.sandbox.translate("security.roles"),horizontal:this.sandbox.translate("security.roles.permissions"),all:this.sandbox.translate("security.roles.all"),none:this.sandbox.translate("security.roles.none"),vertical:k},values:{vertical:l,horizontal:d,titles:this.sandbox.translateArray(e)},data:j}}]),this.sandbox.dom.removeClass(a,"loading")}.bind(this))},l=function(a){h.permissions[a.section][a.value]=a.activated},m=function(){this.sandbox.emit("sulu.permission-tab.save",h)},n=function(a){this.sandbox.emit("sulu.header.toolbar.state.change","edit",a,!0)},o=function(){this.sandbox.on("husky.matrix.changed",n.bind(this,!1))},p=function(a,b){var c=[];return this.sandbox.util.each(b.permissions,function(b,d){return d.context===a?(c=d.permissions,!1):void 0}),c};return{name:"Sulu Security Object Permission Tab",view:!0,templates:["/admin/security/template/permission-tab/form"],initialize:function(){h.id=this.options.id,h.type=this.options.type,h.securityContext=this.options.securityContext,j.call(this),k.call(this),i.call(this),o.call(this)}}});