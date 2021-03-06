// ----- JavaScript functions for image update ----- \\

// Show image update message in the menu
jQuery().ready(function()
{
    jQuery("#imageMelding").show();
    jQuery("#imageStatus").html(string1 + "0 / " + totalsize);
    console.log('Images handled array:');
    console.log(images);
    applyAjax(1);
});

// Recursive function that sends image indices to PHP until the
// whole array has been covered
function applyAjax(counter){
    jQuery.ajax({
        type: "POST",
        url: 'admin.php?page=rentman-shop&update_images',
        datatype: "json",
        data: JSON.stringify({ image_array : images, array_index : arrayindex }),
        contentType: 'application/json; charset=utf-8',
        success: function(){
            console.log(arrayindex);
            delete images[arrayindex];
            jQuery("#imageStatus").html(string1 + counter + " / " + totalsize);
            arrayindex = Object.keys(images)[0];
            console.log(arrayindex);
            if (counter + 1 <= totalsize){
                applyAjax(counter+1);
            } else {
                jQuery("#imageMelding").html(string2);
            }
        }
    });
}