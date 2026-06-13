# Mobile app integration (Flutter — parent & teacher)

These two files are the ONLY app-side changes, already applied to the dev
copy of NxtScools_Managment_parent_teacher and verified with `dart analyze`
(0 errors). Each adds one additive `case 'timetable':` to the existing
notification-type switch (plus its import) so the ERP's timetable push
deep-links to the timetable screen:

- lib/teacherViews/homePage/notification_teacher/notification_page.dart
    case 'timetable': Estu.navigateTo(context, timetable(isInClassDetails: false));
- lib/views/notification/notification.dart
    case 'timetable': Estu.navigateTo(context, Timetable(studntDetails: widget.studntDetails));

Everything else is server-side: published timetables flow through the apps'
EXISTING endpoints (teacher/time-table, parent/student-class-time-table),
substitutions through temporary_assign_teacher — zero further app work.
