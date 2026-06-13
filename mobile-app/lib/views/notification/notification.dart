// import 'package:Estudent_School_managment_teacher_parent/constants/imageConstant.dart';
// ignore_for_file: unnecessary_null_comparison

import 'package:NXT_School_managment_teacher_parent/api_services/ApiHelper.dart';
import 'package:NXT_School_managment_teacher_parent/constants/apiConstants.dart';
import 'package:NXT_School_managment_teacher_parent/constants/teacherConstants/imageConstant.dart';
import 'package:NXT_School_managment_teacher_parent/globalFuctions/globalFunctions.dart';
import 'package:NXT_School_managment_teacher_parent/views/bottomNavigationParent.dart';
import 'package:NXT_School_managment_teacher_parent/views/dairy/dairyPage.dart';
import 'package:NXT_School_managment_teacher_parent/views/homePage/components/noticeBoard.dart';
import 'package:NXT_School_managment_teacher_parent/views/notification/componants/StudentNoticesDetailsPage.dart';
// import 'package:Estudent_School_managment_teacher_parent/views/notification/componants/notice_board_parent.dart';
import 'package:NXT_School_managment_teacher_parent/views/notification/componants/notification_parentleavedetails.dart';
import 'package:cached_network_image/cached_network_image.dart';

import 'package:flutter/material.dart';
import 'package:flutter_shimmer/flutter_shimmer.dart';
import 'package:flutter_spinkit/flutter_spinkit.dart';
import 'package:get/get.dart';
import 'package:lottie/lottie.dart';
import 'package:sizer/sizer.dart';
import '../../api_services/userApi.dart';
import '../../constants/constants.dart';
import '../../models/notificationModel.dart' as NOTIFICATION;
import '../../responsive.dart';
import '../assessments/assessments.dart';
import '../timeTable/timeTable.dart';

class Notifications extends StatefulWidget {
  const Notifications(
      {Key? key, this.isFromeBottomNavigation, this.studntDetails})
      : super(key: key);
  final isFromeBottomNavigation;
  final studntDetails;
  @override
  State<Notifications> createState() => _NotificationsState();
}

class _NotificationsState extends State<Notifications> {
  // ignore: unused_field
  String _notificationStatus = "No notifications cleared yet.";

  // Your existing clearNotificationApi function
  Future<void> clearNotificationApiparent(BuildContext context) async {
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

  void _onparentButtonClick(BuildContext context) {
    clearNotificationApiparent(context);
  }

  Widget build(BuildContext context) {
    return Scaffold(
        appBar: AppBar(
          backgroundColor: tWhite,
          shadowColor: Colors.white,
          scrolledUnderElevation: 0.0,
          centerTitle: true,
          leading: widget.isFromeBottomNavigation == 'yes'
              ? Container()
              : GestureDetector(
                  onTap: () {
                    Estu.navigateBack(context);
                  },
                  child: Container(
                      margin: EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: Image.asset(
                        Images.ARROWLEFT,
                        scale: 4,
                      )),
                ),
          title: Text(
            "notifications".tr,
            style: TextStyle(
                color: tBlack,
                fontSize: isTab(context) ? 10.sp : 15.sp,
                fontWeight: FontWeight.w600),
          ),
        ),
        body:
        
         WillPopScope(
          onWillPop: () async {
            return await Estu.forceNavigateTo(
                context,
                BottomNavigationParent(
                  tabIndexId: 0,
                  studntDetails: widget.studntDetails,
                ));
          },
          child: FutureBuilder<NOTIFICATION.NotificationsModel>(
              future: UserAPI().getNotifications(context, '0'),
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return ListView.builder(
                    itemCount: 5,
                    shrinkWrap: true,
                    itemBuilder: (BuildContext context, int index) {
                      return ProfileShimmer();
                    },
                  );
                  // Center(
                  //   child: Container(
                  //     height: isTab(context) ? 10.h : 14.h,
                  //     child: SpinKitThreeBounce(
                  //       color: tPrimaryColor,
                  //       size: 30.0,
                  //     ),
                  //   ),
                  // );
                }
                if (snapshot.hasError) {
                  print(snapshot.error.toString());
                }

                if (snapshot.hasData) {
                  return Container(
                    child: NotificationPagination(
                      notifyData: snapshot.data,
                      studntDetails: widget.studntDetails,
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
            onPressed: () => _onparentButtonClick(context),
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
  const NotificationPagination({Key? key, this.notifyData, this.studntDetails})
      : super(key: key);
  final notifyData;
  final studntDetails;

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
            95) {
      print('End Scroll');
      page = (page + 1);
      UserAPI().getNotifications(context, page.toString()).then((val) {
        // currentPage = currentPage++;
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
      padding: EdgeInsets.symmetric(vertical: 8, horizontal: 5),
      itemCount: notifyList.length,
      controller: scrollController,
      itemBuilder: (context, index) {
        var notificationList = notifyList[index];
        return GestureDetector(
          onTap: () {
            String notificationType =
                notificationList.notificationtype.toString();
            print('Notification Type: $notificationType'); // Debugging line

            switch (notificationType) {
              // AI Timetable: new/updated class timetable published from the ERP
              case 'timetable':
                Estu.navigateTo(
                  context,
                  Timetable(studntDetails: widget.studntDetails),
                );
                break;

              case 'teacher_leave':
                String notificationtypeId =
                    notificationList.notificationtypeId.toString();
                Estu.navigateTo(
                  context,
                  NotificationsDetailsparentleavePage(
                      notificationDetailsId: notificationtypeId),
                );
                print('Notification Id: $notificationtypeId'); // Debugging line
                break;

              case 'student_notice' || 'student_note':
                String notificationtypeId =
                    notificationList.notificationtypeId.toString();
                Estu.navigateTo(
                  context,
                  StudentNoticesDetailsPage(
                      notificationDetailsId: notificationtypeId),
                );
                print('Notification Id: $notificationtypeId'); // Debugging line
                break;
              case 'notice_board':
                String notificationtypeId =
                    notificationList.notificationtypeId.toString();
                Estu.navigateTo(
                    context,
                    NoticeBoardPage(
                      studntDetails: widget.studntDetails,
                    ));
                print('Notification Id: $notificationtypeId'); // Debugging line
                break;
              case 'student_assessment':
                String notificationtypeId =
                    notificationList.notificationtypeId.toString();
                Estu.navigateTo(
                  context,
                  Estu.navigateTo(context,
                      Assessments(studntDetails: widget.studntDetails)),
                );
                print('Notification Id: $notificationtypeId'); // Debugging line
                break;
              case 'student_dairy':
                String notificationtypeId =
                    notificationList.notificationtypeId.toString();
                Estu.navigateTo(
                  context,
                  Estu.navigateTo(
                      context, DairyPage(studntDetails: widget.studntDetails)),
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
            margin: EdgeInsets.symmetric(vertical: 0.5.h, horizontal: 2.w),
            decoration: BoxDecoration(
                boxShadow: [tBoxShadow],
                color: tWhite,
                borderRadius: BorderRadius.circular(10)),
            child: Padding(
              padding: EdgeInsets.symmetric(vertical: 2.w, horizontal: 1.5.h),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Container(
                  //   child: CircleAvatar(
                  //       radius: isTab(context) ? 18.sp : 23.sp,
                  //       backgroundImage: AssetImage(Images.PROFILE)),
                  // ),
                  Flexible(
                    flex: 4,
                    child: Container(
                      height: isTab(context) ? 9.h : 9.h,
                      width: isTab(context) ? 14.w : 17.w,
                      decoration: BoxDecoration(
                        color: tWhite,
                        borderRadius: BorderRadius.circular(0),
                      ),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(100),
                        child: CachedNetworkImage(
                          imageUrl: notificationList.imageUrl ??
                              'default_image_url', // Provide a default image URL
                          fit: BoxFit.fill,
                          // maxWidthDiskCache: 300,
                          progressIndicatorBuilder:
                              (context, url, downloadProgress) =>
                                  SpinKitThreeBounce(
                            size: isTab(context) ? 10 : 10,
                            color: tSecondaryColor,
                          ),
                          errorWidget: (context, url, error) =>
                              const Icon(Icons.error),
                        ),
                      ),
                    ),
                  ),

                  Flexible(
                    flex: 8,
                    child: Padding(
                      padding: EdgeInsets.only(left: 10, right: 5),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            notificationList.title,
                            style: TextStyle(
                                fontSize: isTab(context) ? 9.sp : 12.sp,
                                fontWeight: FontWeight.w500,
                                fontFamily: 'nonito',
                                color: tBlack,
                                fontStyle: FontStyle.normal),
                          ),
                          Text(
                            Estu.dateTime(notificationList.createdOn)
                                .toString(),
                            style: TextStyle(
                              fontSize: isTab(context) ? 7.sp : 10.sp,
                              fontWeight: FontWeight.w500,
                              fontFamily: 'nonito',
                              color: tGray,
                              fontStyle: FontStyle.normal,
                              // color: tprimaryGray
                            ),
                          ),
                          SizedBox(
                            height: isTab(context) ? 0.8.h : 0.8.h,
                          ),
                          Container(
                            child: Text(
                              notificationList.description,
                              style: TextStyle(
                                color: tBlack,
                                fontSize: isTab(context) ? 7.sp : 9.sp,
                                fontWeight: FontWeight.w400,
                                fontFamily: 'nonito',
                                fontStyle: FontStyle.normal,
                              ),
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}
