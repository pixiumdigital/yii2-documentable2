//=== FILE UPLOAD REORDER
function fileUploadFileSort(event, params) 
{
  csv = '';
  // console.log("event", event);
  // console.log("params", params);
  let key = params.stack[params.newIndex].key;
  console.log('Widget File sorted ', 
      params.previewId, 
      params.oldIndex, params.newIndex, 
      //params.stack
      "key="+key
  );

  // 0 1 2 3 4 5 6 7
  // move 5 before 1
  // 0 5[1 2 3 4]6 7
  // move left => + 1 to in betweens
  // move right => -1 to in betweens
  $.ajax({
    type: "POST",
    url: "/document/move-rank",
    data: { 
      id: key,
      i_before: params.oldIndex,
      i_after: params.newIndex,
    },
    success: function (test) {
        console.log("success-2", test);
    },
    error: function (exception) {
        console.log("failure-2", exception);
        alert(exception);
    }
  });
}