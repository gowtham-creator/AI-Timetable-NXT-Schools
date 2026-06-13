import 'package:NXT_School_managment_teacher_parent/api_services/ApiHelper.dart';
import 'package:NXT_School_managment_teacher_parent/api_services/teacherApiServices/userApi.dart';
import 'package:NXT_School_managment_teacher_parent/constants/teacherConstants/apiConstants.dart';
import 'package:NXT_School_managment_teacher_parent/constants/teacherConstants/constants.dart';
import 'package:NXT_School_managment_teacher_parent/globalFuctions/globalAppbar.dart';
import 'package:NXT_School_managment_teacher_parent/globalFuctions/globalFunctions.dart';
import 'package:NXT_School_managment_teacher_parent/models/teacherModels/teacherNotificationsModel.dart'
    as NOTIFICATION;
import 'package:NXT_School_managment_teacher_parent/responsive.dart';
import 'package:NXT_School_managment_teacher_parent/teacherViews/homePage/notification_teacher/components/teacherNotificationsDetails_page.dart';
import 'package:NXT_School_managment_teacher_parent/teacherViews/homePage/notification_teacher/components/techernoticedetails.dart';
import 'package:NXT_School_managment_teacher_parent/teacherViews/homePage/homePage.dart';
import 'package:NXT_School_managment_teacher_parent/teacherViews/timeTable.dart/timeTable.dart';
import 'package:flutter/material.dart';
import 'package:flutter_shimmer/flutter_shimmer.dart';
import 'package:flutter_spinkit/flutter_spinkit.dart';
import 'package:get/get.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import 'package:sizer/sizer.dart';

import '../../../constants/teacherConstants/imageConstant.dart';
import '../../applyLeaves/components/history.dart';
import '../../bottomNavigation.dart/bottomNavigation.dart';
import '../../leaveReportPage/leaveReport.dart';

// import '../../models/notificationModel.dart' as NOTIFICATION;
class Notifications extends StatefulWidget {
  const Notifications({Key? key, this.isFromeBottomNavigationteacher})
      : super(key: key);
  final isFromeBottomNavigationteacher;
  @override
  State<Notifications> createState() => _NotificationsState();
}

class _NotificationsState extends State<Notifications> {
  String _notificationStatus = "No notifications cleared yet.";

  // Your existing clearNotificationApi function
  Future<void> clearNotificationApi(BuildContext context) async {
    bool? result = await showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          backgroundColor: tWhite,
          title: Text("Clear Notifications"),
          content: Text("Are you sure you want to clear all notifications?"),
          actions: <Widget>[
            TextButton(
              onPressed: () {
                Navigator.of(context)
                    .pop(false); // Close the dialog and return false
              },
              child: Text("No"),
            ),
            TextButton(
              onPressed: () {
                Navigator.of(context)
                    .pop(true); // Close the dialog and return true
              },
              child: Text("Yes"),
            ),
          ],
        );
      },
    );

    // If the user confirms (pressed "Yes")
    if (result == true) {
      var jsonMap;
      var headers = await ApiHelper().getHeader(context);
      var url = CLEAR_NOTIFICATION + headers['auth_code'];

      // Making the API call
      jsonMap = await ApiHelper().getTypeGet(context, url);
      print(jsonMap);

      // Update the notification status based on the API response
      setState(() {
        _notificationStatus = "All notifications were cleared successfully!";
        SnackBar(
          content: Text('All notifications were deleted successfully!'),
          duration:
              Duration(seconds: 2), // Duration the snackbar will be visible
        );
      });
    } else {
      // Do nothing if the user cancels (pressed "No")
      print("Notification clearing canceled.");
    }
  }

  void _onButtonClick(BuildContext context) {
    clearNotificationApi(context);
  }

  Widget build(BuildContext context) {
    return Scaffold(
        appBar: AppBar(
          backgroundColor: tWhite,
          shadowColor: Colors.white,
          scrolledUnderElevation: 0.0,
          centerTitle: true,
          leading: widget.isFromeBottomNavigationteacher == 'yes'
              ? Container()
              : GestureDetector(
                  onTap: () {
                    Estu.navigateTo(
                        context,
                        BottomNavigation(
                          tabIndexId: 0,
                        ));
                  },
                  child: Container(
                      margin: EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Image.asset(
                        Images.ARROWLEFT,
                        color: tPrimaryColor,
                        scale: 4,
                      )),
                ),
          title: Text(
            "notifications".tr,
            style: GoogleFonts.nunito(
                color: tBlack,
                fontSize: isTab(context) ? 12.sp : 15.sp,
                fontWeight: FontWeight.w700),
          ),

          // actions: [Center(
          // child:

          // Container(
          //   margin: EdgeInsets.symmetric(horizontal: 5),
          //       width: 90, // Set width to control the size
          //       height: 30, // Set height for better appearance
          //       decoration: BoxDecoration(
          //         borderRadius: BorderRadius.circular(6), // Rounded corners
          //         gradient: LinearGradient(
          //           colors: [const Color.fromARGB(255, 117, 183, 237), const Color.fromARGB(255, 165, 200, 215)], // Gradient color
          //         ),
          //         boxShadow: [
          //           BoxShadow(
          //             color: Colors.black26, // Shadow color
          //             blurRadius: 1,
          //             offset: Offset(0, 1), // Shadow position
          //           ),
          //         ],
          //       ),
          //       child: MaterialButton(
          //         onPressed: () => _onButtonClick(context),
          //         shape: RoundedRectangleBorder(
          //           borderRadius: BorderRadius.circular(6),
          //         ),
          //         child: Text(
          //           'Clear',
          //           style: TextStyle(
          //             fontSize:isTab(context)?10.sp: 11.sp,
          //             color: Colors.white,
          //             fontWeight: FontWeight.bold,
          //           ),
          //         ),
          //       ),
          //     ),

          // ),],
        ),
        body: WillPopScope(
          onWillPop: () async {
            return await Estu.forceNavigateTo(
                context,
                BottomNavigation(
                  tabIndexId: 0,
                ));
          },
          child: FutureBuilder<NOTIFICATION.TeacherNotificationsModel>(
              future: UserAPI().getTeacherNotifications(context, '0'),
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return ListView.builder(
                    itemCount: 5,
                    shrinkWrap: true,
                    itemBuilder: (BuildContext context, int index) {
                      return ProfileShimmer();
                    },
                  );
                }
                if (snapshot.hasError) {
                  print(snapshot.error.toString());
                }

                if (snapshot.hasData) {
                  return Container(
                    margin: EdgeInsets.symmetric(vertical: 8),
                    child: NotificationPagination(
                      notifyData: snapshot.data,
                    ),
                  );
                } else {
                  return Align(
                    alignment: Alignment.center,
                    child: Lottie.asset(Images.NO_NOTIFICATION_LOTTIE,
                        height: MediaQuery.of(context).size.height * 0.3),
                  );
                }
              }),
        ),
        floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,
        floatingActionButton: Container(
          margin: EdgeInsets.symmetric(vertical: 20),
          width: 60, // Set width to control the size
          height: 60, // Set height for better appearance
          child: FloatingActionButton(
            onPressed: () => _onButtonClick(context),
            backgroundColor:
                Colors.transparent, // Transparent for the container's design
            elevation: 0, // Remove FAB shadow
            child: Container(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(100),
                gradient: LinearGradient(
                  colors: [
                    Color.fromARGB(255, 117, 183, 237),
                    Color.fromARGB(255, 165, 200, 215)
                  ],
                ),
                boxShadow: [
                  BoxShadow(
                    color: Colors.grey, // Shadow color
                    blurRadius: 0.1,
                    offset: Offset(0, 1), // Shadow position
                  ),
                ],
              ),
              child: Center(
                child: Icon(
                  Icons.clear, // Cross icon
                  color: Colors.white,
                ),
              ),
            ),
          ),
        ));
  }
}

class NotificationPagination extends StatefulWidget {
  const NotificationPagination({Key? key, this.notifyData}) : super(key: key);
  final notifyData;
  @override
  State<NotificationPagination> createState() => _NotificationPaginationState();
}

class _NotificationPaginationState extends State<NotificationPagination> {
  ScrollController scrollController = new ScrollController();
  int page = 0;

  late List<NOTIFICATION.Detail> notifyList;
  late double scrollPosition;
  _scrollListener() {
    if (scrollController.position.maxScrollExtent > scrollController.offset &&
        scrollController.position.maxScrollExtent - scrollController.offset <=
            20) {
      print('End Scroll');
      page = (page + 1);
      UserAPI().getTeacherNotifications(context, page.toString()).then((val) {
        // currentPage = currentPage++;
        // ignore: unnecessary_null_comparison
        if (val.details != null) {
          setState(() {
            // currentPage = currentPage + 1;
            notifyList.addAll(val.details);
          });
        } else {
          return Center(
            child: Text('End of data'),
          );
        }
      });
    }
  }

  void initState() {
    scrollController = ScrollController();
    notifyList = widget.notifyData.details;

    scrollController.addListener(_scrollListener);

    super.initState();
  }

  @override
  void dispose() {
    scrollController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ListView.builder(
      itemCount: notifyList.length,
      controller: scrollController,
      itemBuilder: (context, index) {
        var notificationList = notifyList[index];
        print("dddddddddddddddddddddddddddddddddddddd$notificationList");
        print(notificationList.toString());
        var currentIndex = index + 1;
        return GestureDetector(
          onTap: () {
            String notificationType =
                notificationList.notificationtype.toString();
            print(notificationList);
            print('Notification Type: $notificationType'); // Debugging line

            switch (notificationType) {
              // AI Timetable: published timetable or substitution assignment
              case 'timetable':
                Estu.navigateTo(
                  context,
                  timetable(isInClassDetails: false),
                );
                break;

              case 'teacher_leave':
                String notificationtypeId =
                    notificationList.notificationtypeId.toString();

                ///////////////
                Estu.navigateTo(context, LeaveHistory());
                // Estu.navigateTo(
                //   context,
                //   NotificationsDetailsPage(
                //       notificationDetailsId: notificationtypeId),
                // );
                print('Notification Id: $notificationtypeId'); // Debugging line
                break;

              ///teacher notice details
              case 'teacher_notice':
                String notificationtypeId =
                    notificationList.notificationtypeId.toString();
                Estu.navigateTo(
                  context,
                  NotificationNoticesDetailsPage(
                      notificationDetailsId: notificationtypeId),
                );
                print('Notification Id: $notificationtypeId'); // Debugging line
                break;
              case 'student_leave':
                String notificationtypeId =
                    notificationList.notificationtypeId.toString();
                Estu.navigateTo(
                  context,
                  LeaveReport(),
                );
                print('Notification Id: $notificationtypeId'); // Debugging line
                break;

              default:
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: Text('Details not available'),
                    duration: Duration(microseconds: 5),
                  ),
                );
            }
          },
          child: Container(
            margin: EdgeInsets.symmetric(vertical: 0.5.h, horizontal: 1.h),
            padding: EdgeInsets.symmetric(vertical: 0.5.h, horizontal: 1.5.h),
            decoration: BoxDecoration(
                boxShadow: [tBoxShadow],
                color: tWhite,
                borderRadius: BorderRadius.circular(10)),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                // Container(
                //   child: CircleAvatar(
                //       radius: isTab(context) ? 18.sp : 23.sp,
                //       backgroundImage: AssetImage(Images.PROFILE)),
                // ),
                Center(
                  child: CircleAvatar(
                    backgroundColor: tlightblue,
                    radius: isTab(context) ? 33 : 20,
                    child: Center(
                      child: Text(
                        currentIndex.toString(),
                        style: TextStyle(
                            fontSize: isTab(context) ? 10.sp : 12.sp,
                            fontWeight: FontWeight.w500,
                            fontFamily: 'nonito',
                            color: tWhite,
                            fontStyle: FontStyle.normal),
                      ),
                    ),
                  ),
                ),
                // Container(
                //   height: isTab(context) ? 9.h : 9.h,
                //   width: isTab(context) ? 14.w : 17.w,
                //   decoration: BoxDecoration(
                //     color: tWhite,
                //     borderRadius: BorderRadius.circular(0),
                //   ),
                //   child: ClipRRect(
                //     borderRadius: BorderRadius.circular(100),
                //     child: CachedNetworkImage(
                //       imageUrl: notificationList.imageUrl,
                //       fit: BoxFit.fill,
                //       // maxWidthDiskCache: 300,
                //       progressIndicatorBuilder: (context, url, downloadProgress) => SpinKitThreeBounce(
                //         size: isTab(context) ? 10 : 10,
                //         color: tSecondaryColor,
                //       ),
                //       errorWidget: (context, url, error) => const Icon(Icons.person),
                //     ),
                //   ),
                // ),
                SizedBox(
                  width: isTab(context) ? 2.8.w : 2.8.w,
                ),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    // mainAxisAlignment: MainAxisAlignment.  spaceEvenly,
                    children: [
                      SizedBox(
                        height: isTab(context) ? 0.5.h : 1.h,
                      ),
                      Text(
                        notificationList.title,
                        style: TextStyle(
                            fontSize: isTab(context) ? 10.sp : 12.sp,
                            fontWeight: FontWeight.w500,
                            fontFamily: 'nonito',
                            color: tBlack,
                            fontStyle: FontStyle.normal),
                      ),
                      SizedBox(
                        height: isTab(context) ? 0.5.h : 1.h,
                      ),
                      Text(
                        Estu.dateTime(notificationList.createdOn).toString(),
                        style: TextStyle(
                          fontSize: isTab(context) ? 8.sp : 10.sp,
                          fontWeight: FontWeight.w500,
                          fontFamily: 'nonito',
                          color: tGray,
                          fontStyle: FontStyle.normal,
                          // color: tprimaryGray
                        ),
                      ),
                      SizedBox(
                        height: isTab(context) ? 0.5.h : 1.h,
                      ),
                      Container(
                        child: Text(
                          notificationList.description,
                          style: TextStyle(
                              color: tBlack,
                              fontSize: isTab(context) ? 8.sp : 9.sp,
                              fontWeight: FontWeight.w400,
                              fontFamily: 'nonito',
                              fontStyle: FontStyle.normal,
                              overflow: TextOverflow.ellipsis),
                          maxLines: 2,
                        ),
                      ),

                      // Container(
                      //   width: isTab(context) ? 70.w : 70.w,
                      //   height: isTab(context) ? 4.h : 5.h,
                      //   child: Text(
                      //     // Handle null and empty values
                      //     notificationList.notificationtype?.isNotEmpty ==
                      //             true
                      //         ? notificationList.notificationtype
                      //         : 'No Notification Type',
                      //     style: TextStyle(
                      //       color: tBlack,
                      //       fontSize: isTab(context) ? 8.sp : 9.sp,
                      //       fontWeight: FontWeight.w400,
                      //       fontFamily: 'nonito',
                      //       fontStyle: FontStyle.normal,
                      //     ),
                      //   ),
                      // ),
                      // Container(
                      //   width: isTab(context) ? 70.w : 70.w,
                      //   height: isTab(context) ? 4.h : 5.h,
                      //   child: Text(
                      //     // Handle null and empty values
                      //     notificationList.notificationtype?.isNotEmpty ==
                      //             true
                      //         ? notificationList.notificationtypeId
                      //         : 'No Notification ID',
                      //     style: TextStyle(
                      //       color: tBlack,
                      //       fontSize: isTab(context) ? 8.sp : 9.sp,
                      //       fontWeight: FontWeight.w400,
                      //       fontFamily: 'nonito',
                      //       fontStyle: FontStyle.normal,
                      //     ),
                      //   ),
                      // ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
