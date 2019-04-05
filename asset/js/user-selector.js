$(document).ready(function() {

    $('#user-selector-button').click(function(e) {
        e.preventDefault();
        var sidebar = $('#user-selector');
        Omeka.openSidebar(sidebar);
    });

    $('.selector .selector-child').click(function() {
        var user = $(this).data('user');

        console.log(user)

        $('#content form input[name="user_id"]').val(user['o:id']);

        var htnl = "<a target='_blank' href='"+ user['@id'] +"'>"+ user['o:name'] +"</a>";
        $('#content form .selected-user').html(htnl);


        var sidebar = $('#user-selector');
        Omeka.closeSidebar(sidebar);
    });

});
